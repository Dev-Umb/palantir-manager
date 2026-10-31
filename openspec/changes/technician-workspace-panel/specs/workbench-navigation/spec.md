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
