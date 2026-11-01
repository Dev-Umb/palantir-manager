## ADDED Requirements
### Requirement: Document-faithful production workspace
The system SHALL provide the documented overview, category lists, detail editing, material demand plans and applied arrival comparison with database persistence.
#### Scenario: Existing data survives upgrade
- **WHEN** an existing workspace opens the upgraded page
- **THEN** existing production and arrival rows remain unchanged and reference demand plans initialize only when absent
#### Scenario: Explicit comparison scope
- **WHEN** a comparison selection changes without applying
- **THEN** the previous result remains and unapplied scope is indicated
#### Scenario: Missing and ambiguous quantities
- **WHEN** weights are absent or mixed loads lack item weights
- **THEN** missing values remain distinct from zero and mixed weights remain unallocated
### Requirement: Authorized atomic persistence
The system SHALL authorize every affected domain and reject stale writes while preserving existing extensions and retained capabilities.
#### Scenario: Conflicting changes
- **WHEN** a stale workspace attempts save or restore
- **THEN** no data is overwritten and user input remains available
#### Scenario: Restricted account
- **WHEN** a user lacks access to one domain
- **THEN** the combined dataset is denied and the existing authorized single-domain entry remains available
#### Scenario: Complete backup
- **WHEN** an authorized user exports and restores a full project
- **THEN** arrivals, production, demands and extensions are included and restored atomically with an undo snapshot
