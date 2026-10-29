## ADDED Requirements
### Requirement: Private bounded standard invoice recognition
The system SHALL recognize an authorized owner's uploaded PDF with text extraction first and local OCR for scanned pages, without an LLM or public file URL. It SHALL enforce bounded execution and reject unsupported or ambiguous invoices without inventing fields.
#### Scenario: Standard electronic invoice
- **WHEN** a PDF contains an invoice number and explicit small-form tax-inclusive total
- **THEN** recognition returns supported invoice fields and integer cents, never substituting subtotal or tax for the total.
#### Scenario: Scanned invoice or unavailable tools
- **WHEN** the PDF lacks a text layer
- **THEN** local Chinese OCR is attempted within limits; missing tools, timeout, unreadable text or conflicting invoices produces a recoverable result and leaves the uploaded file intact.
#### Scenario: Unauthorized or repeated access
- **WHEN** a different user or a user without submission permission requests recognition
- **THEN** access is denied before reading or processing the file; concurrent duplicate processing is bounded and caches remain user scoped.
### Requirement: Explicit mobile confirmation
Both mobile clients SHALL expose recognition status and retry independently of uploading, preserve manual entry, and apply a recognized amount only after explicit user confirmation.
#### Scenario: Existing amount and multiple attachments
- **WHEN** a user applies a recognized invoice amount
- **THEN** the current amount and replacement amount are shown for confirmation; other expense items remain unchanged and multiple invoice amounts are never silently summed.
#### Scenario: Account change or immutable submission
- **WHEN** an account changes, attachment is removed, or a form is submitted during recognition
- **THEN** a late result cannot alter the new account, removed attachment or frozen submission.
