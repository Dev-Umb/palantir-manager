## ADDED Requirements
### Requirement: Compact mini program expense summary
The mini program SHALL group clearly categorized expenses for display only, sum integer cents exactly, and limit each displayed expense description to twenty Unicode characters. Unknown or ambiguous categories SHALL remain separate. Original expense data and attachments SHALL remain unchanged.
#### Scenario: Multiple invoices in one category
- **WHEN** expenses clearly represent the same category such as postage or lodging
- **THEN** the detail page displays one category row with their exact summed amount.
#### Scenario: Ambiguous expense
- **WHEN** an expense cannot be classified reliably
- **THEN** it remains a separate row with a description no longer than twenty characters and its original amount.
#### Scenario: Invoice access
- **WHEN** a user needs supporting evidence
- **THEN** invoice preview, individual download and all-invoice ZIP remain accessible without showing verbose recognition metadata by default.
#### Scenario: Preserved approval
- **WHEN** an authorized current approver opens a reimbursement
- **THEN** existing approval controls and sequential authorization remain unchanged.
