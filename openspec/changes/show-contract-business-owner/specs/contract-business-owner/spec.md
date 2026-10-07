## ADDED Requirements

### Requirement: Project-sourced contract owner
The contract register SHALL expose a readonly responsible salesperson name derived from the linked project's business_owner_user_id and the current active user name, without copying editable ownership into the contract.

#### Scenario: Existing and multiple contracts
- **WHEN** existing contracts reference a project with a responsible salesperson
- **THEN** each contract shows that salesperson in list, detail and export without resaving historical contracts

#### Scenario: Owner or name changes
- **WHEN** a project's responsible salesperson or that account's name changes
- **THEN** subsequent contract reads reflect the current name

#### Scenario: Missing relationship or owner
- **WHEN** a contract has no valid project, the project has no owner, or the owner account is deleted
- **THEN** the salesperson field is empty without inferring an alternate person

### Requirement: Consistent contract queries and preserved access
The system SHALL use the same project-sourced name for contract searches, salesperson filters, sorting and CSV export. Existing visibility scopes, contract readonly behavior and all other contract fields MUST remain unchanged.

#### Scenario: Query by salesperson
- **WHEN** an authorized user searches, filters or sorts by the salesperson name
- **THEN** the result uses the same current name that the table and export show

#### Scenario: Unauthorized editing or viewing
- **WHEN** a user attempts to directly edit contract ownership or view an otherwise inaccessible contract
- **THEN** existing write restrictions and visibility scopes continue to apply
