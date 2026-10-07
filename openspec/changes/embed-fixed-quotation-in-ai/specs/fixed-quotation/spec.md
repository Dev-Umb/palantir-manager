## ADDED Requirements
### Requirement: Fixed quotation in existing assistant
The system SHALL support fixed-template quotations in the existing AI conversation without adding a module or navigation item. It SHALL preserve all existing AI and archive capabilities.
#### Scenario: User supplies comprehensive prices
- **WHEN** a user requests two product prices with 13% tax and delivery included
- **THEN** the assistant presents one confirmation card with those prices and asks only for missing fields
- **AND** it SHALL NOT request material market prices or fabricate price breakdowns.
- **AND** equivalent tax and delivery wording such as 13% and 含运送到价 SHALL normalize to the fixed template terms, while actual conflicts remain visible.
### Requirement: Retained document fidelity
The system SHALL generate by patching the original template, preserving company/fixed text, fonts, geometry, layout, relationships and the embedded seal. Exported text SHALL be black; the original red seal remains unchanged. Multiple lines SHALL clone the original detail-row formatting, supporting at most three single-page detail rows. The seal image, size and horizontal position SHALL remain unchanged; only its vertical offset SHALL move by the height of added rows to retain its original relation to the signature.
#### Scenario: Two detail lines
- **WHEN** a user confirms two products
- **THEN** the generated DOCX contains two original-format detail rows with confirmed values
- **AND** every package part other than document.xml remains byte-for-byte identical.
### Requirement: Confirmed owner-only output
The system SHALL generate only on user confirmation and freeze the result in the user's existing run artifact. Download SHALL require the same owner and existing AI permission plus quotation role access.
#### Scenario: Different owner
- **WHEN** another user requests generation or download of an existing artifact
- **THEN** access is denied without disclosure or mutation.
#### Scenario: Conflicting confirmation
- **WHEN** a generated artifact is submitted with different values
- **THEN** the system rejects the change and preserves the generated output.
#### Scenario: Incomplete or incompatible values
- **WHEN** fields are missing or tax/transport conflicts with fixed template text
- **THEN** generation is rejected with a readable message and the user's card input remains.

### Requirement: Black text and PDF export
The system SHALL offer black-text DOCX and PDF downloads from each generated quotation. PDF SHALL convert the same black-text DOCX without reconstructing its layout. Existing frozen quotation bytes and inputs SHALL remain unchanged; legacy downloads SHALL derive a black-text copy. Both downloads SHALL retain owner, role and AI permission checks.
#### Scenario: Historical red quotation
- **WHEN** its owner downloads an already-generated red-text quotation
- **THEN** a black-text copy is returned without mutating its stored bytes or metadata.
#### Scenario: PDF download
- **WHEN** the owner selects PDF
- **THEN** the PDF preserves the confirmed content, original fonts, landscape layout and seal.
#### Scenario: Conversion failure
- **WHEN** PDF conversion is unavailable or fails
- **THEN** the user receives a readable failure, and the stored quotation and DOCX download remain usable.
