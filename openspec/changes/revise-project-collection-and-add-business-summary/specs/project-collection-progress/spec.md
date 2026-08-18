## ADDED Requirements

### Requirement: Project collection progress uses occurred amount only

The system MUST calculate project collection progress as paid amount divided by occurred amount, MUST NOT fall back to contract amount, and MUST NOT cap a valid result at 100 percent.

#### Scenario: Positive occurred amount is available

- **WHEN** a project has occurred amount 200 and paid amount 50
- **THEN** the project MUST expose collection progress as 25 percent

#### Scenario: Collection exceeds occurred amount

- **WHEN** a project has occurred amount 100 and paid amount 120
- **THEN** collection progress MUST be 120 percent rather than 100 percent

#### Scenario: Occurred amount cannot be used as a denominator

- **WHEN** occurred amount is missing, invalid, non-finite, zero, or negative
- **THEN** collection progress MUST be unavailable and rendered as `—`
- **AND** contract amount MUST NOT be used as a replacement denominator

### Requirement: Existing projects can be recalculated safely

The system MUST provide an idempotent, preview-first operation that recalculates stored project collection progress with the approved formula.

#### Scenario: Recalculation is previewed

- **WHEN** an operator invokes the recalculation without explicit execution
- **THEN** the operation MUST report scan and expected-change counts
- **AND** MUST NOT update projects, audit logs, or timestamps

#### Scenario: Recalculation is executed

- **WHEN** an authorized operator explicitly executes the recalculation
- **THEN** every eligible existing project MUST be evaluated with the same production formula used by future project saves
- **AND** only the `payment_progress` field of projects whose percentage changes MUST be written
- **AND** every existing amount and adjacent project field MUST remain unchanged
- **AND** the operation MUST NOT query or depend on the deprecated standalone finance ledger

#### Scenario: Recalculation is repeated

- **WHEN** the executed recalculation is run again without source-data changes
- **THEN** it MUST report no further project changes

### Requirement: Adjacent project amount behavior remains distinct

The collection-progress formula change MUST NOT silently redefine unpaid amount, uninvoiced amount, payment status, or project-editing behavior.

#### Scenario: Project progress is recalculated

- **WHEN** the new collection-progress formula changes a project percentage
- **THEN** existing unpaid amount, uninvoiced amount, payment status, and project-editing rules MUST continue using their pre-change contracts
