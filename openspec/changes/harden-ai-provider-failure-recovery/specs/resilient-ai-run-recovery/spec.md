## ADDED Requirements

### Requirement: Recoverable AI failures use the existing attempt budget

The AI run worker MUST release a recoverable provider failure for another attempt while attempts remain, regardless of whether the failed attempt already emitted answer text or projected tool output.

#### Scenario: Provider fails after partial answer and tool output

- **WHEN** an AI run emits partial answer or artifact events and then encounters a recoverable provider failure before the final attempt
- **THEN** the run returns to queued state, clears its partial projections, publishes a retry event, and releases with the configured backoff

#### Scenario: Provider fails without partial output

- **WHEN** an AI run encounters the same recoverable failure before emitting output
- **THEN** the existing retry behavior remains available

### Requirement: Retry replaces partial live output

The retry event MUST instruct clients to replace the failed attempt's answer and projections while preserving the activity trace that a retry occurred.

#### Scenario: Client receives retry after streamed content

- **WHEN** the client has applied answer, artifact, source, provenance, or data-quality output and receives `run.retrying` with reset intent
- **THEN** those partial values are cleared and the run remains active for the next attempt

### Requirement: Provider HTTP diagnostics are bounded and sanitized

The system MUST retain the provider HTTP status and at most 500 characters of a sanitized response excerpt for provider request failures, without retaining credentials, authorization values, request payloads, or full response bodies.

#### Scenario: JSON error body contains secret fields

- **WHEN** the provider response contains an error message plus token, password, API-key, authorization, or secret fields
- **THEN** the diagnostic retains the useful error text, replaces sensitive values with `[REDACTED]`, and stays within the length limit

#### Scenario: Recoverable failure is retried

- **WHEN** a sanitized provider diagnostic is captured for an attempt that will retry
- **THEN** the diagnostic is present in the retry event and safe structured log context

#### Scenario: Attempts are exhausted

- **WHEN** a provider request failure reaches the final attempt
- **THEN** the sanitized diagnostic is stored in the run error and published with `run.failed`

### Requirement: Terminal failure boundaries remain intact

Non-recoverable failures and recoverable failures with no attempts remaining MUST terminate without release and preserve the existing user-facing error message contract.

#### Scenario: Provider authentication fails

- **WHEN** the provider returns an authentication failure classified as non-recoverable
- **THEN** the run fails immediately without release

#### Scenario: Recoverable failure reaches attempt three

- **WHEN** a recoverable provider failure occurs on the third attempt
- **THEN** the run fails once without scheduling a fourth attempt
