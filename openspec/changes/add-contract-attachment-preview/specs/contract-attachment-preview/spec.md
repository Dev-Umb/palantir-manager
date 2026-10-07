## ADDED Requirements

### Requirement: Preview saved processing letters and contracts

The system MUST offer authorized users an online preview and a separate original download for saved processing-letter and contract attachments from project contract details, project editing history, and contract ledger/detail entry points.

#### Scenario: A user opens any historical attachment
- **WHEN** an authorized user chooses a saved processing-letter or contract attachment from any supported entry point
- **THEN** the system MUST display that exact attachment in an application reading surface with its category and ordinal
- **AND** all other historical attachments MUST retain their order and individual availability

#### Scenario: Existing download consumers continue working
- **WHEN** a user follows an existing authorized download URL
- **THEN** the system MUST preserve its attachment download behavior and existing URL contract

#### Scenario: Adjacent fields remain unchanged
- **WHEN** a user views statement attachments or attachments on unrelated objects
- **THEN** the system MUST preserve their existing actions and permissions without automatically enabling the new preview experience

### Requirement: Read supported documents on mobile and desktop

The system MUST render valid PDF, JPEG and PNG attachments inside the supported Web reading surface, with close/back and authorized download actions. PDFs MUST provide page position, page navigation, zoom and fit width. Images MUST provide zoom, pan and fit reset.

#### Scenario: A user reads a multipage PDF
- **WHEN** a user opens a valid supported multipage PDF
- **THEN** the system MUST render readable pages and allow navigation between the first and last pages with correct page boundaries
- **AND** it MUST NOT require launching another document application for successful preview

#### Scenario: A user opens an image on a phone
- **WHEN** an authorized user opens a JPEG or PNG on a supported phone browser
- **THEN** the image MUST initially fit the available width and support magnification and panning without hiding the close control

#### Scenario: Rendering fails
- **WHEN** loading or rendering fails, including a damaged or password-protected document that cannot be displayed
- **THEN** the reading surface MUST show a clear failure state, allow retry where meaningful and retain download only when authorized
- **AND** it MUST NOT remain in an indefinite blank or loading state

### Requirement: Enforce attachment authorization for every read

The system MUST enforce authentication, existing object view permissions, record visibility, field eligibility, attachment index ownership, private-path validation and actual MIME restrictions before disclosing preview metadata or bytes. Content responses MUST use inline disposition, the validated MIME, private no-store caching and nosniff protection.

#### Scenario: A user tampers with the record or index
- **WHEN** a user requests an inaccessible record, an ineligible field, an invalid index or an unauthorized file path
- **THEN** the system MUST refuse the request without returning file metadata or bytes

#### Scenario: Permissions change after the reader opens
- **WHEN** access is revoked or authentication expires before a subsequent metadata, content, HEAD or Range request
- **THEN** that request MUST be denied according to the existing authentication and authorization contract
- **AND** the reader MUST show an appropriate state instead of rendering a login page as a document

#### Scenario: An original file is unavailable
- **WHEN** the attachment reference exists but the file is missing or fails the allowed MIME check
- **THEN** the system MUST return a safe unavailable response without exposing storage paths

### Requirement: Preserve upload and editing semantics

The system MUST preserve upload limits, append-only historical attachments, contract status conditions and project save behavior. Previewing or closing a document MUST NOT mutate business records or discard pending edits.

#### Scenario: Upload succeeds
- **WHEN** a project save with new attachments succeeds and the server returns the persisted list
- **THEN** the new attachments MUST become individually available for preview along with all previous attachments

#### Scenario: Upload is pending or fails
- **WHEN** files have only been selected or the save fails
- **THEN** the system MUST distinguish those files from saved attachments and MUST NOT present them as successfully uploaded
- **AND** existing saved attachments and pending form inputs MUST be preserved

#### Scenario: A user returns to an edited project
- **WHEN** a user closes the reader or uses its supported back action while editing a project
- **THEN** the system MUST restore the same editing context, pending text, selected files and position without submitting the form

#### Scenario: Older contract files exist
- **WHEN** the verified implementation baseline contains a supported legacy single-file contract attachment
- **THEN** the system MUST retain its authorized availability without rewriting its storage reference or inventing a processing-letter classification

### Requirement: Keep client integration evidence explicit

The implementation MUST distinguish Web browser validation, WebView validation and native client validation, and MUST reuse the established client authentication contract for any native integration.

#### Scenario: Web validation completes before native integration
- **WHEN** Web preview checks pass but a native client has not been integrated and tested
- **THEN** delivery records MUST identify Web as verified and native integration as pending
- **AND** Web success MUST NOT be claimed as native client success

### Requirement: Present each attachment in a distinct card

The system MUST display each saved processing-letter or contract attachment in an individually bordered, spaced card with an appropriate file icon and readable label. Desktop cards MUST expose preview and download; compact mobile cards MAY use a single preview action with download inside the reader.

#### Scenario: Multiple attachments appear in a ledger cell
- **WHEN** a contract has multiple processing-letter or contract attachments
- **THEN** each attachment MUST have a distinct visual container and usable click target without concatenated labels
- **AND** constrained table cells MUST retain access to every attachment through a clearly labelled attachment tray

#### Scenario: A reference image contains extra actions
- **WHEN** the new card design is rendered
- **THEN** it MUST NOT introduce delete actions or change upload size/count limits based on the reference image
