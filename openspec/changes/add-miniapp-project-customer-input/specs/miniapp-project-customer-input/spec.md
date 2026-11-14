## ADDED Requirements
### Requirement: Customer creation with project
The mini program MUST reuse the Web customer profile contract for authorized project creation.
#### Scenario: Enter a customer
- **WHEN** an authorized user enters customer name and address and saves a new project
- **THEN** the client MUST submit customer_profile in the single project request, reusing the server name/address matching and transaction
#### Scenario: Conflict
- **WHEN** preview reports existing shared profile differences
- **THEN** the client MUST show current and submitted values and MUST NOT save without explicit confirmation
#### Scenario: Cancel or failure
- **WHEN** preview fails or the user cancels a conflict
- **THEN** the client MUST preserve the draft and MUST NOT create a customer separately
### Requirement: Preserve existing paths
Existing project editing and customer/contact selection MUST remain available under their existing permissions.
#### Scenario: Existing customer
- **WHEN** a user selects an existing customer
- **THEN** the client MUST retain existing contact selection and MUST NOT silently overwrite its master data
#### Scenario: Restricted account or editing
- **WHEN** the server denies customer management or an existing project is edited
- **THEN** the existing form behavior MUST remain unchanged without adding automatic customer creation

### Requirement: Secondary customer form
Customer details MUST be entered on a secondary page while the main project form retains its original fields and selectors.
#### Scenario: Enter from customer selector
- **WHEN** an authorized new-project user opens customer creation from the existing customer selector
- **THEN** a secondary form MUST receive the current name or draft, and returning confirmed details MUST preserve every unrelated project value
#### Scenario: Cancel or logout
- **WHEN** the user cancels the secondary form or the session changes
- **THEN** no customer MUST be created and no stale customer details MUST be returned to the project
#### Scenario: Independent customer creation
- **WHEN** the form is opened from the customer list
- **THEN** its existing immediate customer/contact save and detail navigation MUST remain unchanged

#### Scenario: Minimal customer form
- **WHEN** the secondary customer form opens
- **THEN** the client MUST prefill the search term, show name/address/contact/phone first, and keep optional classification fields expandable without discarding their values
#### Scenario: Search as typed
- **WHEN** the user types in the customer selector
- **THEN** only the latest customer search response MUST update results and existing relation selectors MUST retain their behavior
