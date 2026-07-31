## Context

`RunAiHarness` already releases recoverable failures with backoff, but only while its local `$answer` is empty. Tool-oriented prompts commonly stream a short transition before the tool call, so a transient provider failure during the continuation request bypasses the existing retry path. The browser has already applied `answer.delta` and `artifact.upsert` events by that point, while the provider `RequestException` still contains an HTTP response that is not projected into the run diagnostic.

## Goals / Non-Goals

**Goals:**

- Use the existing job attempt budget for every recoverable provider failure.
- Replace partial live output when a retry starts so the next attempt is the only current result.
- Retain enough sanitized provider response detail to diagnose the next terminal or transient failure.
- Preserve terminal behavior for non-recoverable failures and exhausted attempts.

**Non-Goals:**

- Change Ark endpoint, model, `store` semantics, provider failover, worker concurrency, job timeout, or conversation history limits.
- Add schema, dependency, permission, tool, or business-workflow changes.
- Expose credentials, request payloads, full provider bodies, or unsanitized diagnostics.

## Decisions

### Retry at the existing job boundary

Remove the partial-answer guard from the existing recoverable branch and keep `tries=3` with backoff `[1, 3]`. This preserves queue attempt accounting, cancellation checks, conversation locking, and the current terminal failure path.

Alternative considered: retry the continuation HTTP call inside the provider gateway. Rejected for this change because it would duplicate provider request policy in an Ark-specific transport path and would not reset already projected application state.

### Replace partial output explicitly

Before release, clear artifacts, sources, provenance, and data-quality projections on the run. Publish `run.retrying` with `reset_output=true`; the client reducer clears its partial answer and projections while retaining the activity trace. The completed attempt remains the only answer stored in the run snapshot.

### Store bounded sanitized diagnostics

For `RequestException`, retain the status and at most 500 characters of a whitespace-normalized response excerpt. Recursively redact sensitive JSON keys and scrub bearer tokens, credential-like assignments, and common secret prefixes. Do not store request bodies or headers. Log the same allow-listed fields; do not report the original HTTP exception with its potentially unsanitized response summary.

## Risks / Trade-offs

- [A retry can repeat read-only or proposal-preparation tools] → Existing tools do not finalize writes; user confirmation remains the only write boundary, and partial projections are reset.
- [Old live output can remain visible] → Add an explicit reducer reset scenario for `run.retrying`.
- [Provider text can contain secrets or business content] → Bound to 500 characters, redact sensitive structures and token patterns, and never include request data.
- [Persistent invalid requests may consume all attempts] → Preserve classifier recoverability semantics and the maximum of three attempts; known auth/validation failures remain non-recoverable.

## Migration Plan

1. Deploy code without schema migration.
2. Restart workers so queued jobs load the new job class.
3. Run a controlled online AI prompt that streams text, calls a tool, and reaches `run.completed`.
4. Verify queue health and no new unredacted provider payloads in logs.
5. Roll back by restoring the previous code release and restarting workers; no data migration is required.
