## ADDED Requirements

### Requirement: Bounded large contract uploads
The AI contract intake endpoint SHALL accept supported PDF, JPEG and PNG files up to 100 MiB after proposal approval, while preserving authorization, original contents, staging and explicit confirmation before business writes. Other upload endpoints SHALL retain their existing constraints.

#### Scenario: Large supported contract
- **WHEN** an authorized user submits a valid 60 MiB PDF
- **THEN** the endpoint stages the original file and returns the intake status without a transport size rejection
- **AND** recognition failure is reported separately from upload success

#### Scenario: File exceeds the boundary
- **WHEN** a file exceeds 100 MiB
- **THEN** the client or server rejects it with an understandable size message and does not archive business data

#### Scenario: Preserved security and adjacent behavior
- **WHEN** an unauthorized user or unsupported file attempts intake
- **THEN** the existing permission or format checks reject it
- **AND** small supported files and other upload endpoints retain their existing behavior

#### Scenario: Provider cannot consume the document
- **WHEN** a staged file exceeds the verified provider processing limit
- **THEN** the original remains available for retry and the recognition failure is visible
- **AND** the system does not silently truncate or claim successful extraction
