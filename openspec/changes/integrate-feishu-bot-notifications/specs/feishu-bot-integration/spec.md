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
- **THEN** the private reply MUST be sent as an interactive card containing a `lark_md` element
- **AND** the card MUST retain the complete answer instead of replacing it with a summary
- **AND** failed AI Runs MUST retain the existing plain-text failure reply

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
