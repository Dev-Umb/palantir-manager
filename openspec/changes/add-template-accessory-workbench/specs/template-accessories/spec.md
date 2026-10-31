## ADDED Requirements
### Requirement: Independent original workbench
The system SHALL preserve the supplied demo categories, fields, editing, grouping, import/export, trash and backup functions in an independently authorized workbench with a return to the platform.

#### Scenario: Independent access
- **WHEN** a permitted user opens template accessories
- **THEN** the three original categories are available and raw-material records are not included

### Requirement: Database persistence and atomic changes
The system SHALL store shared records and recovery snapshots in a separate database workspace, and private drafts and filters by authenticated user. Mutations SHALL validate fields and versions, reject stale writes and support idempotent retries.

#### Scenario: Batch conflict
- **WHEN** any record in a save batch conflicts or is invalid
- **THEN** the entire batch is rejected and user inputs remain available

#### Scenario: Import matching
- **WHEN** a user previews an Excel import
- **THEN** identical rows are skipped, ambiguous matches block the batch, changed rows require the original update choice, and unrelated records remain

#### Scenario: Restore isolation
- **WHEN** a permitted user restores or undoes a backup
- **THEN** only template-accessory shared data changes and other users' private state and raw materials remain intact
