## ADDED Requirements

### Requirement: Demo interface parity
The system SHALL preserve the supplied demo's four views, controls, styling, calculations, filters, reports, and file exchange behavior, changing only platform access and persistence-related messaging.

#### Scenario: Existing workflows remain accessible
- **WHEN** an authorized maintainer opens the workbench
- **THEN** ledger, entry, monthly summary, original report, backup, restore, and recycle controls remain in their original locations without new workflow pages

### Requirement: Shared database persistence
The system SHALL persist the shared dataset, deleted records, import history, and pre-restore snapshot in the database, and SHALL start with an empty dataset instead of importing historical seed data automatically.

#### Scenario: Shared records and rejected stale writes
- **WHEN** two authorized users read the same dataset and one changes it
- **THEN** both can retrieve the committed data and an outdated write cannot silently replace it

#### Scenario: Atomic and idempotent mutations
- **WHEN** an import conflicts, validation fails, or a request is retried
- **THEN** failed batches leave no partial records and a repeated request does not apply a second mutation

### Requirement: Private database preferences
The system SHALL persist each user's drafts, filter preferences, and backup reminders in the database without exposing them to another user or reporting success before database acknowledgement.

#### Scenario: Reopen and isolation
- **WHEN** a user reopens the workbench on another device
- **THEN** their last committed private state is restored without loading another user's state

### Requirement: Access and protected recovery
The system SHALL enforce platform module permissions on every endpoint and separate whole-dataset recovery from ordinary management.

#### Scenario: Unauthorized recovery
- **WHEN** a user without recovery permission submits restore or undo
- **THEN** the request is rejected while authorized viewing and management remain available

#### Scenario: Restore and undo preserve boundaries
- **WHEN** an authorized user restores a valid original-format backup and later confirms undo at the current revision
- **THEN** the prior module dataset can be recovered without replacing platform users, permissions, other modules, or other users' private preferences
