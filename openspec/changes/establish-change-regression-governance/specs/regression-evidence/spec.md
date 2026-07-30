## ADDED Requirements

### Requirement: Regression evidence is layered

The project MUST assign pure rules to PHPUnit Unit, server contracts to PHPUnit Feature, React rendering and interaction to Vitest, and deployed workflow contracts to explicit online regression.

#### Scenario: Server authorization changes

- **WHEN** a change modifies authorization or data scope
- **THEN** PHPUnit Feature coverage MUST prove allowed, denied, and adjacent preserved cases without treating frontend visibility as server authorization evidence

#### Scenario: Frontend interaction changes

- **WHEN** a change modifies rendering or interaction
- **THEN** Vitest MUST cover the component contract and applicable server-side tests MUST separately cover server behavior

### Requirement: Regression protects target and preservation boundaries

Every behavior regression MUST cover the requested outcome, one explicitly preserved adjacent behavior, and the most likely collateral boundary.

#### Scenario: Filtering or concealment changes

- **WHEN** a change hides, filters, or denies a value or path
- **THEN** automated tests MUST prove both what disappears and what remains visible

#### Scenario: A component or data source is replaced

- **WHEN** an old capability, field, state, or render branch moves to a new implementation
- **THEN** tests MUST exercise the real calling workflow and prove the new entry point plus preserved adjacent and historical or optimistic states when applicable

### Requirement: Delivery evidence is not conflated

Local tests, quality-gate success, commits, pull requests, merges, deployments, and online verification MUST be reported as distinct states.

#### Scenario: Local gate passes

- **WHEN** all local gate checks succeed
- **THEN** the result MUST NOT be described as merged, deployed, or verified online without separate direct evidence

### Requirement: Online regression is explicit and bounded

The mutating online regression MUST be excluded from default local and commit-gate suites and MUST require explicit enablement, mutation authorization, a fixed approved origin, a unique Run ID, and process-only credentials.

#### Scenario: A required safeguard is absent

- **WHEN** any online enablement, mutation authorization, origin, Run ID, or password safeguard is absent or invalid
- **THEN** the online runner MUST exit before sending a request or writing a report
