## ADDED Requirements

### Requirement: Personal settings entry
The system SHALL expose user settings to every authenticated user through desktop left navigation and an accessible mobile navigation entry, preserving existing business navigation and logout behavior.

#### Scenario: Authenticated user opens settings
- **WHEN** an authenticated user selects user settings
- **THEN** the system shows that user's current login email and separate email and password modification forms without requiring administrator permissions

#### Scenario: Guest requests settings
- **WHEN** a guest requests settings or submits either modification form
- **THEN** the system requires authentication and does not modify any account

### Requirement: Change own login email
The system SHALL allow an authenticated user to change only their own login email after verifying the current password and validating email syntax and uniqueness. The user's identity, name, roles and business associations MUST remain unchanged.

#### Scenario: Email saved successfully
- **WHEN** the user supplies a correct current password and an available valid email
- **THEN** the email is saved, a success message is shown, and the new email can be used to log in

#### Scenario: Invalid email change
- **WHEN** the supplied current password is incorrect or the email is invalid or occupied by another user
- **THEN** the system shows field errors and retains the original account data

#### Scenario: Foreign account or role submitted
- **WHEN** the request includes another user's identifier or role changes
- **THEN** those fields cannot change the target account or any roles

### Requirement: Change own password
The system SHALL verify the current password, require a confirmed new password of at least eight characters different from the current password, and save only its secure hash. Failed validation MUST leave account data and reminder status unchanged.

#### Scenario: Successful password change
- **WHEN** the user submits valid current and new passwords with matching confirmation
- **THEN** the new password is saved, the initial-password reminder is cleared, the current session remains usable, sensitive form inputs are cleared, and the old password no longer authenticates

#### Scenario: Failed password change
- **WHEN** the current password is wrong, confirmation mismatches, or the new password violates the rules
- **THEN** the system shows field errors without changing the password or clearing an applicable reminder

### Requirement: Initial password reminder
The system SHALL show an actionable password-change reminder whenever the authenticated account has is_password_changed set to false. The reminder MUST preserve normal login destinations and business access, remain applicable until a successful password change, and expose no password material to the client.

#### Scenario: Initial password identified
- **WHEN** an authenticated account is marked is_password_changed false
- **THEN** the page shows a reminder and a direct password-settings entry while normal business navigation remains available

#### Scenario: Password already changed
- **WHEN** the account has successfully changed its password and is_password_changed is true
- **THEN** subsequent page loads and logins do not show an initial-password reminder

#### Scenario: Existing and newly created accounts
- **WHEN** this change is migrated for existing accounts or a new account is created
- **THEN** is_password_changed defaults to false without altering existing password hashes and the account receives the reminder

#### Scenario: Email-only update or forged flag
- **WHEN** a user changes only their email or submits a client-controlled is_password_changed value
- **THEN** the password-change marker is not cleared

### Requirement: Administrator password reset
The system SHALL allow users with the existing rbac.manage permission to reset a selected user's password from the user management page, after verifying the operator's current password and a confirmed temporary password of at least eight characters. Resetting MUST set is_password_changed to false, rotate the remember token and record the actor and target in an audit without password material. Roles and business associations MUST remain unchanged.

#### Scenario: Successful reset
- **WHEN** an authorized administrator submits valid reset credentials for an existing user
- **THEN** the target can log in with the new temporary password, cannot log in with the old password and receives the password-change reminder again

#### Scenario: Unauthorized or invalid reset
- **WHEN** the operator lacks rbac.manage permission or the operator password or temporary password validation fails
- **THEN** the target's password and marker remain unchanged and no successful reset audit is written

#### Scenario: CLI administrator password provisioning
- **WHEN** the existing administrator command provisions or replaces an account password
- **THEN** that account's is_password_changed marker is false
