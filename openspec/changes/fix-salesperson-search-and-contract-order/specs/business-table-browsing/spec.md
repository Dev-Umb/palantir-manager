## ADDED Requirements

### Requirement: Search visible salesperson names
Project and existing project business summary tables SHALL match the current business owner name while preserving record authorization and existing query filters.

#### Scenario: Search by owner
- **WHEN** a user searches for a business owner name such as 田振
- **THEN** matching authorized records SHALL be returned without requiring an account ID

#### Scenario: Retain adjacent search and scope
- **WHEN** a user searches by project number or combines search with filters
- **THEN** existing number search SHALL remain valid and unauthorized or filtered-out records MUST remain excluded

### Requirement: Contracts follow the default project order
Contracts SHALL follow their associated projects' complete default ordering unless a valid explicit sort is requested.

#### Scenario: Multiple contracts and project ties
- **WHEN** projects have multiple contracts or equal statement positions
- **THEN** contracts SHALL remain grouped by project in the same stable order as the project table

#### Scenario: Unlinked and manual sort
- **WHEN** a contract lacks a valid project or a user explicitly sorts contracts
- **THEN** unlinked contracts MUST remain visible at the end of default results and explicit sorting MUST retain priority

### Requirement: Project names omit redundant number prefixes
Project name labels SHALL display names without prefixed project numbers, retaining identity and independent number fields.

#### Scenario: Label and identity
- **WHEN** a project is displayed as a relation or switch option
- **THEN** its name SHALL omit the number prefix and its ID, code and number search MUST remain unchanged
