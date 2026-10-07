## ADDED Requirements
### Requirement: Quick quotation in existing App assistant
The App SHALL provide quick quotation in its existing AI assistant without a new primary module. It SHALL preserve queries, contract upload and history and reuse existing owner-scoped quotation APIs.
#### Scenario: Confirm supplied prices
- **WHEN** a salesperson requests a quotation
- **THEN** the App presents editable confirmed-price fields and asks only for missing data before generating DOCX.
#### Scenario: Save generated quotation
- **WHEN** the user selects DOCX download
- **THEN** authenticated bytes are saved to a user-selected Android document location and cancellation does not report success.
#### Scenario: Failure and history
- **WHEN** generation fails or a historical quotation is reopened
- **THEN** inputs remain retryable and generated historical files remain downloadable only by their owner.
