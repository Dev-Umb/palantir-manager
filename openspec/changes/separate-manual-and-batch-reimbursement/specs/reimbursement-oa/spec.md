## ADDED Requirements
### Requirement: Compact batch draft editing
Batch drafts SHALL display category totals and descriptions of at most twenty Unicode characters by default, retaining exact integer-cent totals and expandable per-item editing with original attachment mappings. Evidence requests SHALL be deduplicated by upload ID.
#### Scenario: Forty-four invoices
- **WHEN** a draft contains forty-four recognized invoices
- **THEN** the default view shows grouped categories rather than forty-four expanded editors, and expanding preserves every item and attachment.
### Requirement: Manual reimbursement without AI
Manual mini program reimbursement SHALL upload supporting files without automatically starting recognition, showing recognition dialogs, or requiring invoice-content validation. Authorization, file ownership, safe upload limits and valid monetary input SHALL remain enforced.
#### Scenario: Manual upload
- **WHEN** a user attaches a supported file to a manual reimbursement
- **THEN** no AI request is issued and submission depends on entered fields and access boundaries, not recognized invoice contents.
#### Scenario: Batch upload
- **WHEN** the user chooses batch reimbursement
- **THEN** the existing recognition and explicit review workflow remains available.
