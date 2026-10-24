# Design

## Approved capability replacement map
| Existing capability/public fields | New entry point or explicit retirement |
|---|---|
| /procurement-hub discovery, source filters, recommendation sort | replaced by Procurement/Index, JSON method/date/priority/keyword filters |
| notices title/buyer/code/published/deadline and source facts | new payload title/unit/code/published/deadline, full raw details; old records discarded |
| source collection/admin, source status, evidence HTML/files | retired; new source URL and filename-only references do not imply verification |
| independent research jobs/reports/qualification comparisons | retired; platform /ai queries new notices, distinguishes source data from inference |
| bookmarks/subscriptions/follows | retired, old routes return 404 |
| hub company profiles/imports | retired; ERP records untouched |
| queued collector/research jobs and hub:sync schedule | inactive; queued handlers no-op and scheduler removed |
| mobile procurement/report views | replaced with JSON list and details; legacy report view retired |
| general platform AI tools and ERP MCP | preserved; dedicated procurement MCP has no ERP write permissions |

## Data and boundaries
Four new tables: bound hashed collector credentials, validation/publication batches, current notices, immutable incoming observations. Payload fields from the supplied 14-item JSON are preserved. platform + code forms natural identity; batch-local id and URL are not identity. Unknown extensions remain raw payload. Dates retain original text and precision; file acquisition is not registration. UI quantity is always raw with its unit. All fields are strings except invited/framework boolean and evidence array. Batch coverage/exclusions/limitations remain in batch details.

## MCP publication
/mcp/procurement requires a per-client bearer secret; hash-only storage, expiry, revocation and current bound user role checked at every call. validate_procurement_batch validates full structure (2 MiB, 1–200 items), creates a 24-hour snapshot; no public notice yet. publish_procurement_batch requires validation UUID + client-scoped idempotency key. get_procurement_batch only returns this client's results. Snapshot payload/hash is fixed.

One transaction serializes a client with a database lock and resolves notices by unique identity. Same key/hash returns original result; conflicts reject. Identical content produces duplicate, changed same-client content produces pending_update, cross-client identity produces identity_review without record ID disclosure. A batch may have these mixed outcomes; structurally invalid batches fail atomically. Admin manually accepts a changed observation; updates never widen invited visibility. Published response is an immutable receipt, while batch observations show review state. No automatic source-age inference or overwrite.

## Permissions and AI
Existing admin/tender/business hub roles retained. Invited records visible only to the uploader and admin. List, filters, counts, detail and query_procurement share scope. Batch metadata restricted to owning client user/admin. Platform AI also requires ai.harness.view; role changes are checked fresh. The existing agent retains ERP tools and receives this read-only tool with bounded pagination and full matching counts. Sources are untrusted data, not executable instructions. Model quality and external provider availability are not proven by local tool tests.

## Client delivery and rollout
Customer installs resources/skills/procurement-publish and configures an actual HTTPS URL plus bearer environment-variable name. Credentials never enter the Skill or repository. User-specified JSON travels as an object through MCP; HTML/PDF are unnecessary. Retry keeps snapshot/key stable and stops after two uncertain retries. Sample remains external to Git. Tests load it only with PROCUREMENT_SAMPLE_PATH and isolated SQLite.

No old hub data is migrated. Explicit cleanup migration deletes only the nine legacy hub tables' records in foreign-key order. Schema remains for safe old class compatibility. Stop old workers before deployment/migration so already-executing previous code cannot repopulate data; new handlers ignore queued work. Rollback does not restore retired records. Local completion is not deployment.
