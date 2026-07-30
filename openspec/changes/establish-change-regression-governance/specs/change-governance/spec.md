## ADDED Requirements

### Requirement: Material requirements receive reviewed change artifacts

The project MUST create or reference an OpenSpec change before implementing a new user-visible capability or a material change to business behavior, permissions, workflows, public contracts, persistent schemas, events, authentication, authorization, data scope, dependency architecture, or multiple modules or systems.

#### Scenario: Material behavior is requested

- **WHEN** planned work meets any material-change criterion
- **THEN** proposal, applicable design, requirement scenarios, and tasks MUST pass strict validation and receive review before implementation begins

#### Scenario: Narrow work expands

- **WHEN** exempt diagnostics, documentation, tests, maintenance, or behavior restoration introduces a new requirement or crosses a material boundary
- **THEN** implementation MUST pause until the scope is represented and reviewed as an OpenSpec change

### Requirement: Scope preserves adjacent behavior

Every material change MUST identify required changes, required preservation, permitted concealment, required visibility, and prohibited inference before implementation.

#### Scenario: Local rule risks becoming global

- **WHEN** a request applies to one field, scenario, component, role, or module
- **THEN** unrelated paths, names, queries, modules, owners, states, errors, interfaces, and behavior MUST remain distinguishable unless the reviewed scope explicitly changes them

### Requirement: Task completion remains evidence-backed

An OpenSpec task MUST remain incomplete until its described artifact or behavior exists and its stated validation has succeeded.

#### Scenario: Validation has not passed

- **WHEN** a task requires a test, build, strict validation, migration rehearsal, or online run that has not succeeded
- **THEN** its checkbox MUST remain unchecked and prose claims MUST NOT substitute for evidence
