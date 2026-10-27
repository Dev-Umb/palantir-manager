## ADDED Requirements
### Requirement: Authenticated production session
The mini program SHALL authenticate existing platform accounts over HTTPS using server sessions and CSRF protection, and MUST derive identity and permissions from the backend.

#### Scenario: Administrator acceptance login
- **WHEN** the user enters a valid existing administrator account
- **THEN** the mini program shows the actual server identity and authorized data without embedding credentials or granting new roles

#### Scenario: Expired or switched session
- **WHEN** a session expires, logs out or changes identity
- **THEN** protected views and cached data are cleared and in-flight responses from the previous session cannot repopulate them

#### Scenario: Existing clients
- **WHEN** the browser or Flutter client uses its existing login routes
- **THEN** its redirect, CSRF, authorization and session behavior is unchanged

### Requirement: Backend capability preservation
The mini program SHALL preserve the capability and field mapping in design.md and use server-authorized queries and existing mutation rules.

#### Scenario: Search and pagination
- **WHEN** a user searches or filters projects
- **THEN** the server determines the matching visible records and total, and stale requests cannot overwrite newer results

#### Scenario: Incomplete financial information
- **WHEN** a financial amount is missing or the progress denominator is zero
- **THEN** the UI distinguishes missing from zero and does not fabricate a progress percentage or aggregate only one page as a full total

#### Scenario: Partial project edit
- **WHEN** the user saves an edited project
- **THEN** unedited fields, contracts and attachments are retained and backend validation errors preserve the user's draft

#### Scenario: Unavailable capability
- **WHEN** a retained tool has not yet completed its real integration
- **THEN** its entry clearly indicates unavailability and never returns fabricated production results or a false success message

### Requirement: Direct task oriented interface
The mini program SHALL retain four primary destinations while prioritizing search, relevant status, financial details and clear next actions over decorative content.

#### Scenario: Find a project
- **WHEN** a user opens the project tab
- **THEN** search and status filters are immediately available and details retain overview, contracts and collections

#### Scenario: Request failure
- **WHEN** a request fails or access is denied
- **THEN** the UI distinguishes network failure, expired login, forbidden access and genuine empty results with the appropriate retry or login action

### Requirement: Read only production acceptance
The first production acceptance SHALL authenticate and read data only, while preserving manually initiated write capabilities in the implementation.

#### Scenario: Acceptance automation
- **WHEN** the agent verifies the connected production application
- **THEN** it does not create or edit records, mark notifications read, upload files, generate AI tasks or submit other business mutations

#### Scenario: Backend release
- **WHEN** new session endpoints are needed online
- **THEN** tests, quality gate, PR merge, deployment and online acceptance are tracked as separate completed stages
