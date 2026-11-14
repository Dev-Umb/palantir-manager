## ADDED Requirements
### Requirement: Quick customer creation
The mini program MUST reuse the Web quick customer creation contract and authorization, including optional contact creation in the same transaction.
#### Scenario: Authorized creation
- **WHEN** an administrator or business user saves customer details and an optional contact
- **THEN** the client MUST send one request to the existing endpoint and show the saved customer without duplicate submissions
#### Scenario: Failed or forbidden creation
- **WHEN** saving fails or the current account has no permission
- **THEN** the client MUST preserve entered data on failure and MUST NOT show creation actions to unauthorized accounts
#### Scenario: Preserved browsing
- **WHEN** a user searches or opens an existing customer
- **THEN** search, pagination, contacts, related projects and data visibility MUST remain unchanged
### Requirement: Contract file sources
The mini program MUST allow chat files and album/camera images to enter the existing contract intake flow without bypassing review.
#### Scenario: Valid selection
- **WHEN** a user selects a PDF, JPG or PNG file within 100 MB
- **THEN** the client MUST display the file before explicit upload and retain manual confirmation before archiving
#### Scenario: Invalid selection or cancellation
- **WHEN** selection is cancelled, invalid or oversized
- **THEN** the previous file MUST remain selected and errors MUST be visible except for user cancellation
