## ADDED Requirements

### Requirement: Station notifications can be delivered to Feishu privately

The system MUST keep station notifications as the source of truth and MUST asynchronously deliver each new notification occurrence to the recipient's active Feishu binding when the integration is enabled.

#### Scenario: A bound recipient receives a reminder

- **WHEN** a project or tender station notification occurrence commits for a user with an active Feishu binding
- **THEN** one after-commit delivery MUST be queued with a stable idempotency key
- **AND** success MUST store the Feishu message ID without changing the station notification

#### Scenario: Feishu is unavailable

- **WHEN** token retrieval or message delivery times out, is throttled or returns an error
- **THEN** the station notification MUST remain available
- **AND** the delivery MUST retry within bounded policy and retain a sanitized terminal error if exhausted

#### Scenario: Recipient is not bound

- **WHEN** a station notification targets a user without an active Feishu binding
- **THEN** the delivery MUST be recorded as skipped or not queued
- **AND** the station notification MUST remain unchanged

### Requirement: Read station notifications are archived from the notification center

The system MUST hide read project and tender notifications from the notification center while retaining their persisted records and audit evidence.

#### Scenario: A user marks one notification as read

- **WHEN** the recipient marks a project or tender notification as read
- **THEN** the notification MUST no longer appear in that recipient's notification-center list
- **AND** the notification record and read audit evidence MUST remain persisted

#### Scenario: A user marks all notifications as read

- **WHEN** the recipient marks all project and tender notifications as read
- **THEN** none of those read notifications MUST remain visible in the notification center
- **AND** the records MUST NOT be deleted or change their Feishu delivery state

#### Scenario: The same risk produces a new occurrence

- **WHEN** existing reminder synchronization reactivates an archived notification for a new occurrence and clears its read state
- **THEN** the notification MUST become visible again without changing reminder eligibility or cadence

### Requirement: Payment reminders use an actionable project card

The system MUST render Feishu project payment reminders as interactive cards while keeping the station notification and delivery lifecycle unchanged.

#### Scenario: A payment reminder is delivered

- **WHEN** a project payment notification is delivered to a bound recipient
- **THEN** the card header MUST prominently show the project title
- **AND** the card MUST show the responsible salesperson, project status, payment status, payment progress, outstanding amount and reminder occurrence
- **AND** the card MUST provide a `查看项目详情` action linking to the authenticated Palantir project detail route

#### Scenario: Optional financial display data is absent

- **WHEN** payment progress, outstanding amount or responsible salesperson cannot be resolved from the project record
- **THEN** the card MUST show an explicit unavailable placeholder
- **AND** MUST NOT infer or fabricate a value

#### Scenario: An adjacent reminder type is delivered

- **WHEN** a tender reminder or a non-payment project reminder is delivered
- **THEN** its existing text message contract MUST remain unchanged

### Requirement: Feishu events are verified and idempotent

The system MUST verify callback identity, store each event ID at most once, acknowledge valid callbacks promptly and process accepted business work asynchronously.

#### Scenario: Feishu verifies the callback URL

- **WHEN** a challenge request contains the configured verification token
- **THEN** the endpoint MUST return the challenge without starting business work

#### Scenario: Verification fails

- **WHEN** app ID, verification token or configured tenant does not match
- **THEN** the endpoint MUST reject the request without storing or processing the event

#### Scenario: Feishu retries an event

- **WHEN** a previously stored event ID is delivered again
- **THEN** the endpoint MUST acknowledge it without creating another AI Run or reply

### Requirement: Bound users can query Palantir through a read-only Feishu assistant

The system MUST accept P2P text messages and group text messages that explicitly mention the bot from bound, authorized users, create an auditable Feishu-origin AI Run, and reply to the source conversation using only Palantir's existing authorized read tools.

#### Scenario: An authorized user asks about debt or project progress

- **WHEN** a bound user with AI access sends a P2P text query or mentions the bot with a text query in a group
- **THEN** the assistant MUST query only records visible to that Palantir user
- **AND** MUST reply to the P2P user or source group and link the inbound event, AI Run and outbound message evidence
- **AND** MUST NOT expose or execute create, update, delete or proposal-confirmation tools

#### Scenario: A successful AI query returns Markdown

- **WHEN** a Feishu-origin AI Run completes with a Markdown answer
- **THEN** the source-conversation reply MUST be sent as an interactive card whose headings, paragraphs, lists and tables are represented by supported native card elements
- **AND** unsupported Markdown heading and table delimiter syntax MUST NOT remain visible to the user
- **AND** the card MUST retain the complete answer instead of replacing it with a summary
- **AND** failed AI Runs MUST retain the existing plain-text failure reply

### Requirement: Bound users can export authorized query results to new Feishu files

The system MUST allow a bound and authorized Feishu user to explicitly export a Palantir query result to a newly created Feishu cloud document or spreadsheet without granting access to arbitrary existing files.

#### Scenario: A user requests a cloud document export

- **WHEN** a bound user explicitly asks to export a supported Palantir query as a Feishu document
- **THEN** the tool MUST rerun the query using that Palantir user's existing visibility and object permissions
- **AND** MUST create one new bot-owned cloud document containing the result
- **AND** MUST grant only the initiating bound Feishu user view access to the new document
- **AND** MUST return its title, type, exported row count and clickable URL to the source conversation

#### Scenario: A user requests a spreadsheet export

- **WHEN** a bound user explicitly asks to export a supported Palantir query as a Feishu spreadsheet
- **THEN** the tool MUST derive headers and values from the authorized server-side result
- **AND** MUST create one new bot-owned spreadsheet with bounded rows, columns and payload size
- **AND** MUST grant only the initiating bound Feishu user view access to the new spreadsheet
- **AND** MUST return its title, type, exported row count and clickable URL to the source conversation

#### Scenario: The requested data is forbidden or absent

- **WHEN** the object is not visible, a field is invalid or the authorized query returns no rows
- **THEN** the tool MUST NOT create a file
- **AND** MUST return a clear non-success result without fabricating data

#### Scenario: A prompt attempts to operate an existing Feishu file

- **WHEN** a prompt asks the export tool to read, edit, move, overwrite or delete an existing file
- **THEN** the tool MUST reject or remain unavailable for that operation
- **AND** MUST NOT invoke a destructive CLI command

#### Scenario: CLI support is disabled or unavailable

- **WHEN** the production CLI feature flag is disabled, the executable is missing or the CLI returns an error
- **THEN** existing Palantir queries, reminders and replies MUST continue to operate
- **AND** the export request MUST return a sanitized actionable failure without exposing credentials

#### Scenario: An authorized query starts processing

- **WHEN** a bound and authorized user sends a supported P2P text query or mentions the bot with a text query in a group
- **THEN** the bot MUST add the `Typing` reaction to the inbound message before starting the AI Run
- **AND** reaction creation failure MUST NOT block or fail the AI query
- **AND** the bot MUST make a best-effort attempt to remove its reaction after the terminal reply is sent or the reply job is exhausted

#### Scenario: An inbound message is not accepted

- **WHEN** a message is unbound, unauthorized, from a group without mentioning the bot or uses an unsupported message type
- **THEN** the bot MUST NOT add a processing reaction

#### Scenario: A user is unbound or unauthorized

- **WHEN** the sender has no active binding or lacks the existing AI permission
- **THEN** the event MUST be rejected without querying business data

#### Scenario: An unmentioned group or unsupported message arrives

- **WHEN** the event is a group message without bot mention metadata, a non-text message or a bot-generated message
- **THEN** it MUST NOT start an AI Run

#### Scenario: A group user mentions the bot

- **WHEN** a bound and authorized user mentions the bot with a non-empty text task in a group
- **THEN** mention placeholders MUST be removed before the task is sent to the AI
- **AND** the processing reaction MUST be attached to that user's group message
- **AND** the result MUST be sent to the source group using its inbound `chat_id`
- **AND** MUST NOT be redirected to the initiating user's private chat

#### Scenario: A private user queries the bot

- **WHEN** a supported P2P AI query reaches a terminal result
- **THEN** the result MUST continue to be sent privately using the initiating user's `open_id`

### Requirement: Integration secrets remain process-only

The system MUST load the App ID, App Secret and verification token from runtime configuration and MUST NOT persist plaintext credentials in source files, database records, logs, delivery errors or test fixtures.

#### Scenario: A Feishu API request fails

- **WHEN** the client records or logs an error
- **THEN** only sanitized status, API code and bounded message text MAY be retained
- **AND** authorization tokens and application secrets MUST NOT appear
