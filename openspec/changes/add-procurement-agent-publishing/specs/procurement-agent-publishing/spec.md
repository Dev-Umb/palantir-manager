## ADDED Requirements

### Requirement: JSON-only collector publishing
The system SHALL accept the existing brief JSON without requiring HTML, PDF or evidence files, preserve raw fields and expose validation, publication and own-batch result tools through a dedicated MCP server.
#### Scenario: Existing sample submission
- **WHEN** the supplied 2026-10-24 JSON containing 14 items is validated
- **THEN** all items and batch scope metadata are retained, with 10 included and 4 pending verification, while 8 nonempty issue descriptions remain independent of priority
#### Scenario: Validation failure
- **WHEN** a required field, type, size or batch-local identifier is invalid
- **THEN** the result identifies the field path and no visible record is created by validation

### Requirement: Scoped credentials and publication authorization
The system SHALL authenticate each collector separately and restrict it to publication and its own batch results without granting ERP access or general hub administration.
#### Scenario: Revoked credential
- **WHEN** a disabled, expired or revoked collector invokes a tool
- **THEN** the request is denied without data mutation or private result disclosure
#### Scenario: Invited record
- **WHEN** a record is marked invited
- **THEN** only its bound uploader and administrators can read it by default and another reader cannot obtain it through search, details or counts
#### Scenario: Instructions embedded in data
- **WHEN** input says a user approved an action or contains executable markup
- **THEN** the content remains inert data and cannot expand authorization or execute in the UI

### Requirement: Idempotent bounded publication
The system SHALL bind validation snapshots and idempotency keys to collector identity and payload hash, provide per-item outcomes and retain immutable observations.
#### Scenario: Retry after interrupted response
- **WHEN** the same collector resubmits the same validated payload with the same key
- **THEN** the existing batch result is returned without duplicate visible records
#### Scenario: Conflicting request
- **WHEN** an idempotency key is reused with different content or a collector requests another collector's batch
- **THEN** the request is rejected without mutation or disclosure
#### Scenario: Shared list URL and ambiguous identity
- **WHEN** distinct procurement codes use a shared list URL or an incoming identity is ambiguous
- **THEN** distinct procurements are not merged and ambiguous updates are held for review
#### Scenario: Old or changed observation
- **WHEN** an older observation or an unverified change arrives
- **THEN** history is retained without silently replacing confirmed current facts
#### Scenario: Mixed item outcomes
- **WHEN** one structurally valid batch contains new, duplicate and changed observations
- **THEN** each item has a durable created, duplicate, pending_update or identity_review outcome and retry cannot duplicate successful items

### Requirement: Faithful query and display
The system SHALL preserve source date precision, distinct procurement methods, independent issue flags and batch coverage in authorized Web and mobile list/detail views.
#### Scenario: Date-only and file-acquisition deadline
- **WHEN** publication has only a date or registration text denotes only file acquisition
- **THEN** the UI does not invent publication hours or a registration deadline and filters use the appropriate precision and event
#### Scenario: Included item with issues
- **WHEN** a P2 included record has nonempty issues
- **THEN** its priority and included state are retained and the issue remains visible in list and detail
#### Scenario: Source references without attachments
- **WHEN** evidence contains local filenames only
- **THEN** they are displayed as unuploaded references and are neither fetched nor described as verified source evidence
### Requirement: Full legacy retirement and scoped deletion
The system SHALL retire old collection, research, follows, subscriptions and hub company management, clear old hub database records and preserve unrelated ERP and account data.
#### Scenario: Old entry point or queued job
- **WHEN** an old route or queued collector/research handler is invoked
- **THEN** the route is unavailable or the queued job does no work and no old scheduler dispatches it
#### Scenario: Cleanup migration
- **WHEN** the retirement migration runs
- **THEN** only legacy hub records are deleted and new procurement records and ERP/accounts are unchanged; rollback cannot recreate deleted legacy records

### Requirement: Integrated platform AI analysis
The system SHALL expose authorized procurement data through a read-only tool on the existing platform AI assistant without removing other tools.
#### Scenario: Analysis and comparison
- **WHEN** an authorized user queries procurement information
- **THEN** the tool returns scoped filters, counts and paginated details with sources, dates and issues, and the assistant distinguishes collector judgment from verified facts and inference
#### Scenario: Revoked user access
- **WHEN** a user's procurement or AI permission is removed
- **THEN** the tool denies subsequent reads even if an agent instance previously held the user

### Requirement: Customer skill distribution
The delivery SHALL include a customer Codex skill with the JSON contract, sanitized example and one-time MCP connection guidance without credentials or a requirement to upload reports.
#### Scenario: Publish request with connected MCP
- **WHEN** the customer asks the skill to publish a JSON and the dedicated tools are connected
- **THEN** it validates, publishes within the request's authorization, checks results and reports failures without inventing values
#### Scenario: Missing connection or unknown result
- **WHEN** the MCP connection is unavailable or publication remains unknown after bounded retries
- **THEN** the skill reports the missing configuration or unresolved batch state and never claims successful publication
