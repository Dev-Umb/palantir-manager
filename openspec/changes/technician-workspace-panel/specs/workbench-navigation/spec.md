## ADDED Requirements
### Requirement: Technician basic panel
The system SHALL allow workbench technicians with either workbench view permission to enter the basic platform panel while returning no business dashboard data.

#### Scenario: Login from the generic login page
- **WHEN** a workbench technician logs in from the login page
- **THEN** the home destination displays only authorized workbench entry cards and account navigation

#### Scenario: Return to the platform panel
- **WHEN** the technician follows either workbench's panel return link
- **THEN** the basic panel opens successfully without business metrics

#### Scenario: Other business routes remain denied
- **WHEN** the technician requests projects, AI, administration or notifications
- **THEN** access is denied

#### Scenario: Existing administrator retains the dashboard
- **WHEN** an administrator visits home or dashboard
- **THEN** existing visualization and business dashboard behavior is unchanged

### Requirement: Unified production workbench
The system SHALL show material arrivals and template accessories within one page and one navigation sidebar, preserving their separate permissions, database storage, drafts and backup scopes.

#### Scenario: Switch workbench views
- **WHEN** a user switches between material views and accessory categories
- **THEN** the loaded content remains mounted and pending inputs are preserved
- **AND** backup and recovery actions target only the active workbench

#### Scenario: Existing entry points
- **WHEN** a user follows an old authorized workbench entry URL
- **THEN** the user reaches the unified page at the appropriate workbench

### Requirement: Avoid redundant startup transfers
The system SHALL reuse cacheable shared Excel libraries and reuse the initial state response during startup, while still fetching fresh state for subsequent explicit refreshes and mutations.

#### Scenario: Initial load
- **WHEN** a workbench starts
- **THEN** it makes one initial state request and does not embed duplicate Excel library source in its HTML

#### Scenario: Refresh after initial load
- **WHEN** the user refreshes data after startup
- **THEN** current server data is read and stale state cannot overwrite a newer revision
