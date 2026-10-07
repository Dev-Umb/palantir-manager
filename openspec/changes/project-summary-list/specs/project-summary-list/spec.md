## ADDED Requirements

### Requirement: Project summary view
The project list SHALL show project name, owner, customer, unpaid amount in ten-thousand yuan, last payment date, contract status and ten-character remark, with view and permission-aware edit actions. Original fields and operations SHALL remain available in the complete grid.

#### Scenario: Summary and details
- **WHEN** the project list is opened
- **THEN** summary is displayed and full remark and record identity remain available in details

#### Scenario: Read-only record
- **WHEN** a user cannot update a record
- **THEN** viewing remains available and edit is disabled

#### Scenario: Missing amounts
- **WHEN** amount is null or zero
- **THEN** null remains unknown and zero displays 0.00
