## ADDED Requirements

### Requirement: The staged gate selects proportional checks

The local staged quality gate MUST always validate the staged diff and MUST select PHPUnit for backend paths, Vitest plus production build for frontend paths, and strict OpenSpec validation for governance paths.

#### Scenario: Backend-only staged snapshot

- **WHEN** staged files are confined to recognized Laravel backend or PHPUnit paths
- **THEN** the gate runs the staged diff and PHPUnit application checks

#### Scenario: Frontend-only staged snapshot

- **WHEN** staged files are confined to recognized frontend paths
- **THEN** the gate runs the staged diff, Vitest, and production build checks

#### Scenario: Unknown staged path

- **WHEN** any staged production or tooling path is not classified
- **THEN** the gate fails closed by selecting every core check

### Requirement: Delivery runs the full local gate

The canonical delivery gate MUST run staged diff validation, strict OpenSpec validation, PHPUnit application tests, Vitest, and the production frontend build.

#### Scenario: A selected check fails

- **WHEN** any full-gate command returns a non-zero exit code
- **THEN** delivery MUST remain blocked and the failing check MUST be reported without bypassing the hook
