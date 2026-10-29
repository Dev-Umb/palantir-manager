## ADDED Requirements
### Requirement: Authorized procurement mobile browsing
Both mobile clients SHALL render server-authorized JSON procurement records using the approved card and detail design.
#### Scenario: Filter and read
- **WHEN** a user applies method, priority, time basis and date filters
- **THEN** the server query receives those filters with page reset to one, displays server totals, and preserves complete raw fields in detail
#### Scenario: Cancel or invalid date range
- **WHEN** a filter is canceled or its end precedes its start
- **THEN** applied filters and loaded data remain unchanged and invalid input receives visible feedback
#### Scenario: Session or network failure
- **WHEN** access expires, is denied or a network error occurs
- **THEN** no obsolete protected response is applied and errors are not rendered as empty successful results
### Requirement: Mobile source and AI handoff
Both clients SHALL retain original source access and offer an explicit AI draft handoff.
#### Scenario: Analyze a notice
- **WHEN** the user requests analysis from a notice
- **THEN** the record identity is attached to a draft for user confirmation and no AI request is sent automatically
#### Scenario: Existing workbench
- **WHEN** the procurement entry is added
- **THEN** reimbursement creation, personal reimbursement history and existing four-tab navigation remain available with their existing permissions
