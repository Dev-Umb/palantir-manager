## ADDED Requirements

### Requirement: Business-object routes preserve numeric IDs and accept keys

The application MUST bind a business object from either its existing numeric ID or its configured key and MUST return 404 when neither resolves.

#### Scenario: Existing numeric caller creates a record

- **WHEN** an authorized caller posts a valid payload to `/objects/{numeric-id}`
- **THEN** the application creates the record using the resolved object

#### Scenario: Key caller creates a record

- **WHEN** an authorized caller posts the same valid payload to `/objects/{object-key}`
- **THEN** the application creates the record using the same object behavior

#### Scenario: Object does not exist

- **WHEN** a caller posts to an unknown object key
- **THEN** the application returns 404 without a database type error

### Requirement: Malformed UUIDs do not reach database UUID casts

Record route identifiers and submitted relation identifiers MUST be validated as UUIDs before a database lookup that expects a UUID.

#### Scenario: Malformed record route identifier

- **WHEN** a caller updates `/records/not-a-uuid`
- **THEN** the application returns 404 rather than 500

#### Scenario: Malformed relation identifier

- **WHEN** a caller submits a relation value that is not a UUID
- **THEN** the application returns 422 with the Chinese message `关联记录格式不正确`

#### Scenario: Valid relation identifier

- **WHEN** a caller submits an existing permitted UUID relation
- **THEN** the existing relation validation and business derivation continue unchanged

### Requirement: Production not-found responses conceal model classes

When application debug mode is disabled, a model-not-found response MUST NOT expose an internal PHP model class.

#### Scenario: JSON client requests a missing model

- **WHEN** a JSON-expecting request triggers `ModelNotFoundException` in production mode
- **THEN** the response is 404 JSON with `记录不存在或已被删除。` and contains no `App\\Models` text

#### Scenario: Adjacent authorization or validation failure

- **WHEN** an existing request fails authorization or ordinary payload validation
- **THEN** its 403 or 422 status and message contract remain unchanged
