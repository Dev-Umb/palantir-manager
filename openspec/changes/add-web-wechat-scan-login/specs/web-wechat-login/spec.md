## ADDED Requirements
### Requirement: Browser-bound scan login
The system SHALL generate a random short-lived single-use challenge bound to the initiating browser session and SHALL require explicit confirmation by an existing WeChat-bound account.

#### Scenario: Confirm and consume
- **WHEN** a bound user scans and confirms a live challenge
- **THEN** only its originating browser can consume it and log in as that same account, preserving role permissions and redirect behavior

#### Scenario: Expired cancelled replayed or foreign challenge
- **WHEN** a challenge is expired, cancelled, consumed or requested by another browser
- **THEN** authentication is denied and the user can request a new code

#### Scenario: Unbound or revoked identity
- **WHEN** a scanner is unbound or its binding has been revoked before consumption
- **THEN** no Web login is granted; unbound users may use the existing first-binding flow before explicitly confirming

### Requirement: Preserve login capabilities
The system SHALL preserve password login, registration and existing mini-program login behavior.

#### Scenario: Existing password user
- **WHEN** a user signs in using password
- **THEN** the existing authentication and role destination remain unchanged

#### Scenario: Mini-program login continuation
- **WHEN** a scanned challenge requires mini-program login
- **THEN** successful login returns to its confirmation page without automatically approving the browser login
