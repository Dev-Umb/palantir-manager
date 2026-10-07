## ADDED Requirements

### Requirement: Chrome 86 startup compatibility
The application SHALL allow its existing login bootstrap to run when Chrome 86 lacks Object.hasOwn, preserving existing native implementations and authorization semantics.

#### Scenario: Missing runtime API
- **WHEN** the browser lacks Object.hasOwn
- **THEN** dependency execution MUST receive an equivalent own-property implementation before application initialization

#### Scenario: Current browser
- **WHEN** the browser already provides the API
- **THEN** its native implementation and existing page behavior MUST remain unchanged

### Requirement: Visible startup failure
The initial document MUST provide actionable failure feedback when the application cannot mount, without showing failure after successful mounting.

#### Scenario: Failed initialization
- **WHEN** initialization fails or remains unmounted beyond the startup timeout
- **THEN** the user SHALL see a retry action and browser upgrade guidance

#### Scenario: Successful initialization
- **WHEN** the application mounts successfully
- **THEN** startup feedback MUST disappear and MUST NOT replace the page later
