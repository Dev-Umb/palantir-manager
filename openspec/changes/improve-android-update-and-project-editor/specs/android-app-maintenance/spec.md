## ADDED Requirements

### Requirement: Optional Android updates
The app SHALL automatically check on startup and provide manual checking from My Account. It MUST allow postponement and MUST require user confirmation before download and system installation.

#### Scenario: New version exists
- **WHEN** a valid trusted manifest has a higher versionCode
- **THEN** the app MUST show the new version and release notes with update and postpone actions

#### Scenario: Update service is unavailable
- **WHEN** checking fails or the manifest is invalid
- **THEN** login and business access MUST remain available and manual checking MUST show an actionable error

### Requirement: Verified installation handoff
The app MUST validate downloaded package integrity, identity, version and signer compatibility before handing it to the Android installer, without sending business credentials to the update service.

#### Scenario: Invalid download
- **WHEN** integrity or package validation fails
- **THEN** the app MUST refuse installation and offer retry

#### Scenario: Installation is cancelled
- **WHEN** the user cancels system installation or declines permission
- **THEN** the app MUST remain usable and MUST NOT report successful installation

### Requirement: Individual contract attachment removal
The project editor SHALL support long-press and visible individual removal controls for saved and pending attachments. Saved removal MUST be reversible before successful project save and MUST follow server authorization.

#### Scenario: Remove one saved attachment
- **WHEN** the user confirms removal of one file and successfully saves
- **THEN** only that file association MUST be removed while other files, their order and other contracts remain intact

#### Scenario: Cancel or failed save
- **WHEN** removal is cancelled, undone, abandoned or saving fails
- **THEN** saved attachments MUST remain unchanged and failed saving MUST retain the editable draft

#### Scenario: Pending files have equal names
- **WHEN** one of two pending files with equal names is removed
- **THEN** only the selected pending instance MUST be removed

### Requirement: Consistent project editor feedback
The editor SHALL use the home page color and card conventions and MUST retain all fields, permission boundaries, customer conflict confirmation, three tabs and unsaved-exit protection.

#### Scenario: Initial loading or failure
- **WHEN** project data is loading or fails to load
- **THEN** the editor MUST show a labelled loading state or retry action without presenting an empty form as loaded

#### Scenario: Saving
- **WHEN** the user saves changes
- **THEN** the editor MUST show progress, prevent duplicate submission and retain drafts on failure

### Requirement: Shared attachment removal protocol
The existing project contract save interface SHALL accept optional removed_attachments grouped by supported attachment field using stable server-issued tokens. PC Web and Android MUST use the same protocol. Omitted removal parameters MUST preserve old append behavior.

#### Scenario: Stale or foreign token
- **WHEN** a removal token no longer belongs to the submitted contract and field
- **THEN** saving MUST fail without changing persisted records or deleting original files

#### Scenario: Required evidence and permissions
- **WHEN** removal would violate existing contract evidence requirements or the user cannot maintain the project
- **THEN** the existing evidence and authorization rules MUST reject the save
