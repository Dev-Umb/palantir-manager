## ADDED Requirements

### Requirement: Complete contract groups
The table SHALL group authorized filtered contracts by project ID and customer ID, paginate whole groups, and retain individual records and export behavior.

#### Scenario: Group across original pages
- **WHEN** multiple contracts share project and customer
- **THEN** they appear in one group without being split across pages

### Requirement: Summary and details
The view SHALL display summed known amounts in ten-thousand yuan, mark missing amounts, and display earliest to latest signing date. Expanding SHALL show independent fields, statuses and attachments.

#### Scenario: Missing values
- **WHEN** a contract has missing amount or signing date
- **THEN** summary identifies incomplete values and does not invent zero or a date

#### Scenario: Independent contracts
- **WHEN** a group is expanded
- **THEN** each contract retains its independent identity, fields and attachments
