## ADDED Requirements

### Requirement: TOS business uploads
All business attachment, contract and design file uploads SHALL use the private TOS disk without changing the original filename or business reference.

#### Scenario: Web or contract upload
- **WHEN** an authorized user uploads a valid business file
- **THEN** the file is verified in TOS and registered before the upload is reported successful
- **AND** existing field validation and permissions remain enforced

#### Scenario: Storage failure
- **WHEN** TOS writing or verification fails
- **THEN** the upload fails visibly and is not silently saved locally

### Requirement: Verified historical migration
Historical business files MUST retain their logical paths, original bytes, filenames and associations during migration.

#### Scenario: Existing local file
- **WHEN** a local business file is migrated
- **THEN** its TOS size and SHA256 are verified before storage metadata is switched
- **AND** the local original is retained

#### Scenario: Missing or corrupt source
- **WHEN** a referenced file is absent or fails verification
- **THEN** it remains unresolved in the migration report and is not marked migrated

### Requirement: Preserve access boundaries
Attachment preview and download SHALL retain existing authorization and support both migrated and not-yet-migrated files.

#### Scenario: Migrated preview
- **WHEN** an authorized user previews or downloads a migrated file
- **THEN** the existing protected route authorizes access and redirects to a private TOS URL expiring within five minutes

#### Scenario: Unrelated local data
- **WHEN** business files are migrated
- **THEN** system backups, logs and caches remain outside the migration scope

### Requirement: Generated document downloads
Generated quotation DOCX, PDF, business table CSV and timebook XLSX downloads SHALL be stored privately in TOS and delivered with short lived signed URLs after existing authorization.

#### Scenario: Mobile direct download
- **WHEN** a compatible authenticated mobile client requests a file
- **THEN** it follows only the approved private bucket HTTPS URL without sending platform credentials
- **AND** login redirects and unrelated origins remain rejected

#### Scenario: Filtered business export
- **WHEN** an authorized user exports a filtered business table or timebook
- **THEN** the unchanged export bytes and filename are stored privately in TOS
- **AND** existing record visibility, filtering and export permissions remain enforced
