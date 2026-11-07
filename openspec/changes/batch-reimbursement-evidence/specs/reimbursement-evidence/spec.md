## ADDED Requirements
### Requirement: Batch evidence with immediate preview
The system SHALL accept multiple PDF/image files into one reimbursement attachment area and allow authorized preview while upload or recognition is pending.
#### Scenario: A file fails in a batch
- **WHEN** one file fails to upload or recognize
- **THEN** successful files remain available, the failed file has a visible reason and isolated retry, and retry does not duplicate attachments or expenses.
#### Scenario: Preview before upload completes
- **WHEN** a user selects an attachment already queued locally
- **THEN** the client opens the available local image/PDF without waiting for recognition and reports preview failure separately.
### Requirement: Multiple evidence items generate reviewed expense drafts
The system SHALL identify multiple evidence items within PDF pages and images, retain original files and page/region provenance, and automatically add editable expense drafts from supported explicit fields without submitting them.
#### Scenario: Multiple invoices in one PDF
- **WHEN** separate supported invoices are identified across pages or within a page
- **THEN** separate expenses reference their respective evidence and the same authorized source PDF without multiplying stored originals or ZIP entries.
#### Scenario: Payment receipt rather than invoice
- **WHEN** successful payment evidence contains a unique explicit expense amount
- **THEN** its draft identifies payment evidence and payment date without inventing invoice identifiers or treating insurance value as expense amount.
#### Scenario: Ambiguous or failed payment
- **WHEN** a payment failed, was refunded, or has ambiguous amounts
- **THEN** it is flagged for manual resolution and does not silently add a positive expense.
### Requirement: Recognition failures remain actionable
The system SHALL mark failed and partially recognized attachments with a top-right red dot and an accessible textual reason.
#### Scenario: Partial PDF recognition
- **WHEN** only some evidence items in a PDF can be recognized
- **THEN** recognized drafts remain and unresolved pages/regions are visible with retry or manual resolution; opening preview does not clear the failure.
### Requirement: Preserve source ownership and avoid duplicate expense creation
The system SHALL preserve existing permissions, manual data, submission idempotency and historical reimbursement versions, and enforce source ownership for new multi-expense references.
#### Scenario: Retry after user edits
- **WHEN** recognition is retried after a user changes a draft
- **THEN** the retry does not duplicate the expense or silently overwrite the edit.
#### Scenario: Potential invoice and payment match
- **WHEN** an invoice and payment evidence might describe the same transaction
- **THEN** the user explicitly resolves whether to associate them with one expense or keep separate expenses before submission.
#### Scenario: Existing multi-attachment expense
- **WHEN** an older reimbursement is viewed or resubmitted
- **THEN** its existing amounts and attachments remain intact without automatic repartitioning.
