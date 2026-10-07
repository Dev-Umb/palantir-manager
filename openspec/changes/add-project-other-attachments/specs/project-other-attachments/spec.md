## ADDED Requirements

### Requirement: Original attachment filenames
Project and contract attachments SHALL display the recorded original filename instead of numbered attachment labels. New uploads MUST preserve the client filename as display metadata while storage uses generated safe keys.

#### Scenario: Historical imported document
- **WHEN** an attachment has a recorded original filename
- **THEN** its edit and detail views display that filename
- **AND** its authorized preview, download and storage identity remain unchanged

#### Scenario: Missing historical name
- **WHEN** the original filename was not recorded
- **THEN** the stored filename or an explicit unavailable-name label is shown without inventing an original name

### Requirement: Auxiliary project files
The system SHALL expose other attachments on project maintenance and detail entry points using existing authorization, file identity and preview behavior.

#### Scenario: Authorized guarantee upload
- **WHEN** an authorized user adds a payment guarantee to a project
- **THEN** its filename and preview are visible under other attachments
- **AND** contract amount, signed status and processing-letter status are not inferred from it

#### Scenario: Existing genuine documents
- **WHEN** a project has a genuine signed contract or processing letter and an other attachment
- **THEN** the genuine document remains visible and retains its existing status semantics
- **AND** other attachments do not change financial records or collection history

#### Scenario: Other attachments only
- **WHEN** a project has only a payment guarantee and no genuine contract or processing letter
- **THEN** it is not classified as having a contract or processing letter
- **AND** it remains included in the missing-document report

### Requirement: Shared original bindings
The system SHALL permit the specified guarantee to remain associated with Xinbo project043 and additionally with Henan Hongxing project322 without duplicating contract value or losing original provenance.

#### Scenario: Correct the misclassified guarantee
- **WHEN** the authorized correction is applied
- **THEN** the specified original is visible as other attachments on both projects
- **AND** it is absent from project043 processing-letter attachments
- **AND** project322 contract008 and all financial values remain unchanged

#### Scenario: Remove one binding
- **WHEN** an authorized user removes the guarantee from one project
- **THEN** the other project's binding and original file remain accessible to authorized users

#### Scenario: Unauthorized file access
- **WHEN** a user lacks access to the associated project
- **THEN** the other-attachment entry point does not grant access to that project's files
