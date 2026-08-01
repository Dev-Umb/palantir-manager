## ADDED Requirements

### Requirement: Authorized users manage project informed viewers

The system MUST provide a multi-select project field named “知会人员” whose selectable accounts have the business role, and MUST allow only the project owner or an administrator to change it.

#### Scenario: Project owner assigns informed viewers

- **WHEN** the responsible business user saves one or more valid business accounts in “知会人员”
- **THEN** the system MUST persist the distinct account IDs on that project

#### Scenario: Administrator assigns informed viewers

- **WHEN** an administrator changes “知会人员” on any project
- **THEN** the system MUST persist the validated selection

#### Scenario: Unauthorized user attempts to change informed viewers

- **WHEN** finance or a non-owner business user submits a change to “知会人员”
- **THEN** the system MUST reject or ignore the unauthorized change without changing the stored selection

#### Scenario: Non-business account is submitted

- **WHEN** a submitted informed viewer does not exist or lacks the business role
- **THEN** validation MUST fail and the project MUST remain unchanged

### Requirement: Informed business users receive read-only project visibility

The system MUST allow a business user to view a project when their account is selected in “知会人员”, while preventing that user from modifying or deleting the project.

#### Scenario: Informed user views a project

- **WHEN** a business user is selected in a project’s “知会人员” and is not its responsible business user
- **THEN** the project MUST appear in that user’s project list and detail view as read-only

#### Scenario: Informed user constructs an update request

- **WHEN** an informed non-owner business user directly submits an update request for the project
- **THEN** the system MUST return forbidden and MUST preserve every project field

#### Scenario: Unrelated business user views projects

- **WHEN** a business user is neither the project owner nor an informed viewer
- **THEN** that project MUST remain absent from their list and detail selection

### Requirement: Informed projects are visibly identified

The system MUST identify an informed project to the informed non-owner business user without changing the record for other roles.

#### Scenario: Informed user sees project list

- **WHEN** an informed non-owner business user views their project list
- **THEN** the project name MUST be preceded by a visible “知会项目” indicator

#### Scenario: Owner or elevated role views the same project

- **WHEN** the responsible business user, finance, or an administrator views the project
- **THEN** the system MUST NOT label it as an informed project for that user

### Requirement: Adjacent permissions remain unchanged

The informed-viewer capability MUST NOT grant access to linked contracts or add informed users to project reminder recipients.

#### Scenario: Informed user requests a linked contract

- **WHEN** an informed non-owner business user lacks existing ownership-based access to a linked contract
- **THEN** the contract MUST remain outside that user’s visible record set

#### Scenario: Project reminder becomes due

- **WHEN** a reminder is triggered for a project with informed viewers
- **THEN** the existing owner, finance, and administrator recipient rules MUST remain unchanged and informed viewers MUST NOT be added solely because they were informed
