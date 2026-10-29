## ADDED Requirements
### Requirement: Owner scoped direct upload
The system SHALL accept up to 300 PDF or JPG/PNG files per batch, 20 MiB per file and 1 GiB per batch through short-lived private TOS upload authorization, without persistent application-server file storage.
#### Scenario: Retry and ownership
- **WHEN** an owner retries upload confirmation
- **THEN** the same file is retained exactly once and another user cannot access it
### Requirement: Background extraction
The system SHALL process each file independently in a dedicated queue using bounded memory-backed temporary storage and validated Agent output, preserving failures and progress after the user leaves.
#### Scenario: Partial failure
- **WHEN** some invoices fail extraction
- **THEN** successful files produce drafts while failed file names remain visible on the draft list for re-upload
### Requirement: Categorized draft generation
The system SHALL group one expense per invoice by batch and type into drafts of at most 50 items, preserving historical types and requiring human submission.
#### Scenario: Repeated processing
- **WHEN** processing or draft generation is retried
- **THEN** no duplicate expenses are generated and manually edited drafts are not overwritten
#### Scenario: Uncertain or duplicate invoice
- **WHEN** extraction is uncertain or a duplicate is suspected
- **THEN** the issue must be resolved before submission and other users' records are not disclosed
### Requirement: Mini program batch workflow
The mini program SHALL expose batch reimbursement beside manual reimbursement, show upload progress and redirect to the existing draft list without requiring chat interaction.
#### Scenario: Resume
- **WHEN** a user returns after completed uploads
- **THEN** task progress and drafts remain available from the reimbursement list
### Requirement: Retained approval and clients
The system SHALL retain manual reimbursement, owner-only drafts, existing approval and older app compatibility.
#### Scenario: New category submission
- **WHEN** a user confirms and submits a new-category draft
- **THEN** the current approval flow applies and draft creation alone has not notified approvers

### Requirement: Preserve TOS files
The system SHALL retain all TOS invoice objects, including staging and unconfirmed uploads. Batch reconciliation SHALL only remove local temporary memory files, never TOS objects.
#### Scenario: Old upload remains
- **WHEN** an upload is unconfirmed or older than one day
- **THEN** reconciliation preserves both its original and staging TOS objects
