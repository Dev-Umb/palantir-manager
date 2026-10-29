## ADDED Requirements

### Requirement: One invoice per new expense
Both mobile clients SHALL place each newly added invoice file in its own expense item and SHALL preserve existing historical items without inferring amount allocation.

#### Scenario: Add another invoice
- **WHEN** a user adds an invoice while the current item already contains an invoice
- **THEN** a separate expense item is created for the new invoice, and the existing item remains unchanged.

#### Scenario: Retry a failed invoice upload
- **WHEN** a failed upload is retried
- **THEN** the same expense item is reused and no duplicate item or attachment is created.

#### Scenario: Resubmit historical multi-invoice expense
- **WHEN** a user resubmits a historical expense containing multiple attachments
- **THEN** its existing amount, description and attachments are preserved, and newly added invoices use separate items.

### Requirement: Complete reviewed expense autofill
The clients SHALL support a separately editable invoice_date and SHALL apply recognized amount, date and other supported fields to the matching expense after review in an automatically opened dialog. Inline recognition result lists SHALL NOT be rendered below attachments. The backend SHALL validate and persist the optional date and all three clients SHALL display it in expense details.

#### Scenario: Apply recognized invoice
- **WHEN** a user confirms recognized fields for an invoice
- **THEN** its amount and valid date populate the corresponding fields and remaining recognized fields populate its description while preserving handwritten text and avoiding duplicate appended content.

#### Scenario: Missing or invalid data
- **WHEN** a field is absent, an invalid date is submitted, or applying a description exceeds its length limit
- **THEN** missing fields are not invented or used to erase existing input, invalid dates are rejected, and oversized descriptions require user editing without silent truncation.

#### Scenario: Legacy client and history
- **WHEN** an older client submits without invoice_date or a historical record has no date
- **THEN** submission remains compatible and historical dates are not fabricated.

#### Scenario: State changes during review
- **WHEN** the account, item, attachment, draft values or submission state changes before confirmation completes
- **THEN** stale recognized data is not applied to the new state.

### Requirement: Evidence-based upload diagnosis
The reimbursement flow SHALL distinguish upload and recognition and provide scoped timing evidence without logging invoice content or weakening attachment checks.

#### Scenario: Successful upload and recognition
- **WHEN** an invoice is uploaded and recognized
- **THEN** transfer, storage verification and recognition timing can be distinguished while the full integrity check remains in effect.

#### Scenario: Failed processing
- **WHEN** transfer, storage or recognition fails
- **THEN** the failing stage is identifiable, previously uploaded attachments remain governed by existing handling, and private contents or credentials are not included in diagnostic logs.

#### Scenario: Recognition completes
- **WHEN** a valid invoice recognition completes for a current editable expense
- **THEN** its review dialog opens automatically, reviews are serialized across invoices, and cancel preserves the draft with a retry-review entry available.
