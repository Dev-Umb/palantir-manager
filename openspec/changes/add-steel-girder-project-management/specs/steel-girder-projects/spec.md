## ADDED Requirements

### Requirement: Ordered parallel workbench navigation
The system SHALL show 项目管理 followed by 支架检索 under 钢箱梁工作台, using visible authorized children only, while preserving existing support-library URLs and behavior.

#### Scenario: Authorized navigation
- **WHEN** a user can access both modules
- **THEN** both ordered entries are visible and correctly highlighted on desktop and mobile web
- **AND** the existing support search and exports remain usable

#### Scenario: Only support permission
- **WHEN** a user has support-library access but no project access
- **THEN** 支架检索 remains visible and project links and direct requests are denied

### Requirement: Shared PostgreSQL project state
The system SHALL store project state, material results, source references, checks and notes in the existing Tencent Cloud PostgreSQL database and SHALL NOT treat browser storage as authoritative.

#### Scenario: Continue on another device
- **WHEN** an authorized colleague opens a saved project from another browser
- **THEN** the same committed drawings, material results, checks and notes are loaded

#### Scenario: Conflicting save
- **WHEN** a write carries an outdated revision or references a deleted project
- **THEN** it cannot overwrite committed data and the UI preserves unsaved changes with actionable feedback

### Requirement: Private TOS original files
The system MUST retain original PDF, DWG and XLSX files in private TOS storage with database references and SHALL enforce project authorization on every file retrieval.

#### Scenario: Drawing imports
- **WHEN** valid PDF or DWG files no larger than 200 MB each are imported
- **THEN** the system verifies and stores their original bytes, skips same-content duplicates within the project and retains same-name different-content files

#### Scenario: Upload failure or forbidden download
- **WHEN** TOS persistence fails or the user lacks required project export access
- **THEN** no successful saved/download state is reported and no unauthorized file is disclosed
- **AND** previously committed originals remain intact

### Requirement: Preserve material calculation and review semantics
The system SHALL preserve the supplied prototype's material metrics, detail rows, units, missing/error states, source cells, scope detection, review signatures and notes. Single-span input SHALL display double-span totals once; ambiguous scope MUST remain explicit.

#### Scenario: Import and review
- **WHEN** a valid single-span workbook is imported and an item is checked with a note
- **THEN** the display scales once, the original workbook is preserved, and the check and note survive reload

#### Scenario: Invalid replacement
- **WHEN** a replacement workbook is invalid, unsafe or contains unsupported data
- **THEN** an actionable error is displayed and the previous committed material version is retained

#### Scenario: Export and reimport
- **WHEN** a material workbook with reviews is exported and reimported
- **THEN** its original workbook, valid review records and notes remain available without double multiplication
- **AND** missing values remain distinct from zero

### Requirement: Preserve project and drawing operations without destroying originals
The system SHALL retain create/select/open/back navigation, incomplete markers, individual downloads, collision-safe ZIP export and confirmed removal. Project deletion and drawing removal MUST be logical operations and MUST NOT delete original TOS objects.

#### Scenario: Cancel or confirm removal
- **WHEN** a user cancels a deletion dialog or presses Escape
- **THEN** no project or drawing association is changed

#### Scenario: Confirm project deletion
- **WHEN** an authorized manager confirms deletion for the displayed project
- **THEN** it disappears from active lists, subsequent writes are denied and original TOS objects remain retained

#### Scenario: Export identical names
- **WHEN** two drawings have the same original filename and different content
- **THEN** ZIP export includes both byte-identical originals under distinct safe entry names

### Requirement: Independent scoped permissions
The module SHALL enforce steel-girder-projects.view/manage/export using existing effective permission and restrictive-account conventions, without changing unrelated or support-library permissions.

#### Scenario: Read-only user
- **WHEN** a user has view permission only
- **THEN** project data is visible but mutations, file retrieval and exports are rejected
- **AND** unrelated authorized modules remain available
