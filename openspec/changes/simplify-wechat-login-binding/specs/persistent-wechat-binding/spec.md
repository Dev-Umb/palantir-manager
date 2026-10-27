## ADDED Requirements

### Requirement: Stable account binding credential
The system SHALL provide a cryptographically random persistent binding code per platform account, without automatic expiry or consumption after successful binding.

#### Scenario: Authorized retrieval
- **WHEN** the owner or an administrator with rbac.manage re-verifies their password and requests the account binding code
- **THEN** the system returns the existing code or generates it once, without exposing plaintext through ordinary account-list responses or logs

#### Scenario: Code reset
- **WHEN** an authorized operator explicitly resets a code after password re-verification
- **THEN** the previous code stops working immediately and existing identity bindings, permissions and sessions remain unchanged

### Requirement: Preserve exclusive identity binding
Persistent codes SHALL NOT override existing bindings or allow one WeChat identity to acquire multiple accounts.

#### Scenario: Existing matching identity
- **WHEN** a verified WeChat identity supplies the persistent code for its already bound account
- **THEN** the request establishes a session for that account without duplicating or transferring the binding

#### Scenario: Conflicting identity
- **WHEN** the account or WeChat identity is already bound incompatibly
- **THEN** the request fails without changing the binding and directs the user to explicit backend unbinding

#### Scenario: Explicit unbinding
- **WHEN** authorized unbinding is confirmed
- **THEN** the associated mini program sessions are revoked while the account persistent code remains available for a later binding

### Requirement: Preserve existing users during migration
The transition SHALL preserve existing WeChat bindings and invalidate legacy temporary binding codes without promoting them to permanent credentials.

#### Scenario: Previously bound user
- **WHEN** the upgraded service receives a valid WeChat login for a previously bound account
- **THEN** the account can log in without generating a new persistent code

### Requirement: Single mobile login screen
The mini program SHALL present the actual WeChat login screen directly to unauthenticated users without a separate login-placeholder screen.

#### Scenario: Launch and logout
- **WHEN** a user launches without a session or logs out
- **THEN** the WeChat login screen is shown directly, and successful login opens the workbench while preserving all four business tabs
