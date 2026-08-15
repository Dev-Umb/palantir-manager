## ADDED Requirements

### Requirement: Business users see customers derived from owned projects

The system MUST limit a business user's customer records to customers linked to at least one project owned by that user, plus customers created by that user that are not linked to any project.

#### Scenario: Customer is linked to an owned project

- **WHEN** a customer is linked to at least one project whose responsible business user is the current user
- **THEN** the customer MUST be available in that user's list, detail, search, export, and authorized relation options

#### Scenario: Customer is linked to owned and foreign projects

- **WHEN** the same customer is linked to both a project owned by the current user and a project owned by another business user
- **THEN** the customer MUST remain visible to the current user

#### Scenario: Customer is linked only to foreign projects

- **WHEN** every project linked to a customer is owned by other business users
- **THEN** the customer MUST be absent from the current user's list, detail, search, export, and relation options

#### Scenario: User created an unlinked customer

- **WHEN** the current business user created a customer and no project links to that customer
- **THEN** only that creator and existing elevated roles MUST be able to access it

#### Scenario: Creator's customer becomes linked only to another owner

- **WHEN** a customer created by the current user becomes linked to projects owned only by other business users
- **THEN** creator status MUST NOT keep that customer visible

### Requirement: Customer contacts inherit customer visibility

The system MUST restrict a business user's customer-contact records to contacts whose owning customer is visible to that user.

#### Scenario: Contact belongs to a visible customer

- **WHEN** a contact belongs to a customer visible through an owned project or the unlinked-creator rule
- **THEN** that contact MUST be available through authorized customer and contact interfaces

#### Scenario: Contact belongs to an invisible customer

- **WHEN** a contact belongs to a customer outside the current business user's customer scope
- **THEN** the contact MUST be absent from lists, searches, exports, relation options, and direct customer-management requests

### Requirement: Every customer access path enforces record scope

The system MUST enforce customer visibility on the server for generic object routes, embedded project customer routes, exports, and relation-option searches.

#### Scenario: Business user constructs a direct request

- **WHEN** a business user directly requests or mutates a customer outside their visible scope
- **THEN** the system MUST deny the request without returning or changing that customer's data

#### Scenario: Business user submits an invisible customer to a project

- **WHEN** a business user submits an inaccessible customer ID through a project payload or relation search
- **THEN** validation or authorization MUST fail and the project MUST remain unchanged

### Requirement: Existing elevated customer access remains unchanged

The system MUST preserve the current customer visibility and maintenance permissions of administrators, finance users, and tender users.

#### Scenario: Administrator accesses customers

- **WHEN** an administrator lists, searches, exports, or manages customers
- **THEN** all customers MUST remain in scope

#### Scenario: Finance or tender user accesses customers

- **WHEN** a finance or tender user performs an already-authorized customer action
- **THEN** the result MUST retain the role's existing full customer scope and existing write restrictions

### Requirement: Unlinked customers are prioritized in project customer options

The system MUST order customer options for project creation and editing with globally unlinked customers first, then linked customers, with newest records first inside each group.

#### Scenario: Project editor opens customer options

- **WHEN** visible unlinked and linked customers are available to the current user
- **THEN** every unlinked customer MUST appear before every linked customer, and each group MUST use descending creation time with stable ID ordering

#### Scenario: Existing project customer is outside the first option page

- **WHEN** the project's currently selected customer is not among the first 50 available options
- **THEN** the current customer MUST still be returned as a selected option

#### Scenario: User searches project customer options

- **WHEN** the user searches customer options by name or code
- **THEN** the search MUST remain restricted to visible customers and preserve unlinked-first ordering among matches
