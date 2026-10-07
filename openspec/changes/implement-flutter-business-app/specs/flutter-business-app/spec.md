## ADDED Requirements

### Requirement: Android business application

The application SHALL use Flutter and preserve the approved four navigation entries, homepage AI entry and read-only procurement reference module.

#### Scenario: Business navigation

- **WHEN** a signed-in salesperson opens the application
- **THEN** it MUST show authorized project data from the configured backend and provide projects, customers, notifications and AI entry points

### Requirement: Existing business authorization

Mobile requests MUST inherit existing record and field authorization, canonical unpaid_amount and contract persistence rules. Login MUST use server session authentication with CSRF protection.

#### Scenario: Unauthorized project

- **WHEN** a salesperson requests another salesperson's inaccessible project
- **THEN** the backend MUST deny access without exposing its fields or attachments

### Requirement: Reliable edit and AI flows

The client MUST preserve input after failed requests and require explicit confirmation of AI contract differences before updating business records.

#### Scenario: Backend unavailable

- **WHEN** saving fails
- **THEN** the client MUST show an error and keep the draft without reporting success

### Requirement: Production Android release

Release builds MUST connect to https://palantir.umb.ink using HTTPS and use a dedicated non-debug signing certificate. Signing secrets MUST stay outside version control. Production deployment MUST preserve existing business data and existing Web routes.

#### Scenario: Release environment

- **WHEN** building the production Android APK
- **THEN** the configured backend MUST be the approved production origin and Android MUST reject cleartext traffic

#### Scenario: Missing signing credentials

- **WHEN** a release build has no configured signing key
- **THEN** the build MUST fail rather than fall back to the debug certificate

#### Scenario: Development environment

- **WHEN** starting a debug build without an address override
- **THEN** the existing local emulator backend MUST remain available

### Requirement: Shallow business editing

Project relation fields MUST use matching in-form dropdown controls instead of separate selector pages. Customer contacts MUST support adding, editing and selecting rows inside the project form and persist with the existing project customer profile transaction. Shared customer conflicts and read-only scope MUST remain enforced.

#### Scenario: Select informed people

- **WHEN** a business user opens the informed people field
- **THEN** an in-form multi-select dropdown MUST appear without page navigation and preserve existing selections

#### Scenario: Save inline contacts

- **WHEN** a project editor adds or edits a contact and saves the project
- **THEN** the same existing customer-profile endpoint MUST persist the contact and project together, preserving drafts on failure

#### Scenario: Business navigation depth

- **WHEN** entering projects through customers, notifications or completed AI intake
- **THEN** related records MUST open directly within the project flow without accumulating intermediate read-only pages, and an operation MUST require at most three successive page transitions

### Requirement: Reuse existing backend interfaces

The Flutter client MUST use existing Web session login, Inertia page data and JSON business endpoints without requiring mobile-specific backend routes. Shared auth/nav and existing canUploadContracts props MUST determine client visibility. Existing server authorization remains authoritative.

#### Scenario: Existing production login

- **WHEN** production exposes its existing Web routes without mobile-specific routes
- **THEN** the Android app MUST log in through /login, preserve rotating CSRF/session cookies, and display authorized business pages

#### Scenario: Protocol failure

- **WHEN** login fails, the session expires, a page version changes or a redirect targets another origin
- **THEN** the client MUST report validation or session errors, negotiate page versions with bounded retries, and never forward credentials or cookies to another origin

#### Scenario: Existing writes

- **WHEN** a business write returns JSON or an existing settings action redirects
- **THEN** the client MUST preserve field validation errors and only report success after receiving a valid response, keeping unsaved drafts on failure

### Requirement: Project and customer list filters

Project and customer lists MUST expose a filter sheet using authorized field metadata and the existing objects list query contract. Filters MUST apply on the server across all matching records, combine with keyword search, and support AND/OR conditions, clearing and pagination without new backend routes.

#### Scenario: Apply and retain filters

- **WHEN** the user applies field conditions and then searches, refreshes, loads more, or returns from a record
- **THEN** the existing filters query parameters MUST remain active, with changed conditions restarting at page one and applied conditions visible on the list

#### Scenario: Cancel or clear

- **WHEN** the user cancels editing filter conditions or clears active filters
- **THEN** cancellation MUST preserve the applied conditions and clearing MUST remove only filter conditions while retaining the keyword

#### Scenario: Validation and failed requests

- **WHEN** a condition has a missing value, invalid number or reversed range, or loading fails
- **THEN** invalid conditions MUST be rejected before submission, failed requests MUST preserve search/filter input for retry, and stale responses MUST NOT replace the latest results

#### Scenario: Authorized fields

- **WHEN** the backend supplies the current object's visible fields and relation options
- **THEN** the sheet MUST use these options, exclude unsupported item/file/multiple-relation fields, and never infer new viewing or editing permissions

### Requirement: Load only necessary mobile page data

Project and customer detail requests MUST use existing Inertia partial responses to fetch fresh authorized detail, field metadata and capabilities without downloading the object list. Project editing MUST additionally receive all existing relation options and retain its complete payload and contracts. Authentication, navigation permissions, CSRF, version negotiation and missing-record errors MUST remain enforced.

#### Scenario: Open or refresh a detail

- **WHEN** a user opens or refreshes a project or customer detail
- **THEN** the client MUST request currentObject, selectedRecord, can and current shared auth/nav/errors through the existing endpoint, preserving all detail fields and attachments without substituting cached record data

#### Scenario: Edit a project

- **WHEN** opening an existing or new project editor
- **THEN** the same partial request MUST include relationOptions, preserving customer contacts, contracts, upload metadata and readonly constraints

#### Scenario: Visit bottom navigation

- **WHEN** signing in and subsequently opening bottom navigation entries
- **THEN** unopened tabs MUST NOT load their lists, and an opened tab MUST retain its state when switching away and back, with existing refresh and post-edit reload behavior preserved

#### Scenario: Protocol boundary

- **WHEN** a partial request encounters a version change, expired session or incomplete response
- **THEN** version negotiation MUST retain the requested field selection, authentication failure MUST clear the session, and incomplete data MUST produce an error instead of an empty or editable record


### Requirement: Secure persistent login

The Android client MUST expose a default-enabled keep-signed-in option and reuse the existing /login remember parameter. It MUST persist only verified cookies, encrypted with Android Keystore and excluded from device backup, bound to the exact server origin. It MUST NOT persist plaintext passwords or cached authorization as proof of access.

#### Scenario: Relaunch with a valid remembered session
- **WHEN** the user closes and reopens the app after successful remembered login
- **THEN** the app MUST validate saved cookies through existing authenticated routes and restore current authorized business access without asking for the password again

#### Scenario: Short session expires but remember cookie is valid
- **WHEN** the saved session cookie has expired and the server still accepts its remember cookie
- **THEN** the client MUST omit expired cookies and accept the server's renewed session and CSRF cookies without a password prompt

#### Scenario: Login is not remembered
- **WHEN** the user disables keep-signed-in and logs in
- **THEN** the client MUST send remember=false and MUST NOT retain that authentication after process restart

#### Scenario: Restoration is temporarily unavailable
- **WHEN** startup encounters a connection error or server failure
- **THEN** the app MUST retain saved authentication, hide unvalidated business data, and offer retry or an explicit return to the login form

#### Scenario: Session is invalid or belongs to another origin
- **WHEN** the server rejects authentication, the encrypted record is invalid, or the configured origin differs
- **THEN** the client MUST clear the unusable saved session and show login without leaking cookies to another origin

#### Scenario: Logout and in-flight responses
- **WHEN** the user logs out, including during a network failure
- **THEN** the client MUST remove local saved authentication and a stale in-flight response MUST NOT restore it

#### Scenario: Session cookie rotation or removal
- **WHEN** an authorized response changes or expires cookies on normal, upload or download requests
- **THEN** the cookie jar and ordered encrypted snapshot MUST reflect that change for the next request and next launch


#### Scenario: Absolute root redirect without a trailing slash
- **WHEN** the existing login endpoint redirects to the same origin with an empty URL path
- **THEN** the client MUST treat that path as / for Cookie matching and retain valid root session and CSRF cookies
