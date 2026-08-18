## ADDED Requirements

### Requirement: Business summary is a read-only project projection

The system MUST provide a business summary table at the same main-data navigation level as customer and project, with one row per visible project and without storing independent summary records.

#### Scenario: A summary row is displayed

- **WHEN** a user can view a project
- **THEN** the summary MUST show responsible salesperson, project number, customer name, project name, occurred amount, paid amount, and unpaid amount sourced from that project
- **AND** the row MUST provide a link to the existing project record entry

#### Scenario: Project data changes

- **WHEN** an authorized user changes project data through the existing project workflow
- **THEN** subsequent summary reads MUST reflect the current project values without a separate summary synchronization record

### Requirement: Summary visibility exactly follows project visibility

The summary table MUST require project view permission and MUST apply the same `ProjectVisibility` scope as the project table for every read surface.

#### Scenario: A salesperson opens the summary

- **WHEN** salesperson A opens, searches, sorts, paginates, exports, or follows a row in the summary
- **THEN** only projects visible to salesperson A under the existing project scope MUST be returned
- **AND** projects belonging only to salesperson B MUST not be disclosed

#### Scenario: A user lacks project view permission

- **WHEN** a user does not have `object.project.view`
- **THEN** the summary navigation entry and summary data MUST not be returned

#### Scenario: An administrator opens the summary

- **WHEN** an administrator with full project visibility opens the summary
- **THEN** all otherwise available project rows MAY be returned under the same pagination contract as the project table

### Requirement: Summary cannot become a second editing surface

The system MUST NOT expose create, update, delete, inline-edit, bulk-write, or independent import actions for the business summary.

#### Scenario: A user has project update permission

- **WHEN** that user opens a business summary row
- **THEN** the summary MUST remain read-only
- **AND** any edit action MUST navigate to the existing project table and be re-authorized there

#### Scenario: A write request targets the summary

- **WHEN** a client attempts to create, update, or delete through the summary entry
- **THEN** the server MUST reject the request without changing project data

### Requirement: Customer contacts remain embedded rather than a standalone table

The system MUST show the customer table and MUST NOT expose customer contacts as an independent navigation item, object-switcher tab, list page, or CSV export, while preserving contact maintenance inside the customer and project tables.

#### Scenario: A user opens the main-data workspace

- **WHEN** an authorized business user opens the customer, project, or business summary table
- **THEN** the customer table and business summary table MUST be available
- **AND** no independent customer-contact table entry MUST be shown

#### Scenario: A user maintains contacts from an approved parent table

- **WHEN** an authorized user creates, views, updates, or deletes a customer contact through the existing customer or project workflow
- **THEN** the operation MUST continue to use the retained customer-contact data, permissions, and relationship rules
- **AND** hiding the independent table MUST NOT remove or duplicate contact records

#### Scenario: A user requests the standalone contact table directly

- **WHEN** a user requests the customer-contact list or its CSV export
- **THEN** the server MUST reject that standalone read surface
