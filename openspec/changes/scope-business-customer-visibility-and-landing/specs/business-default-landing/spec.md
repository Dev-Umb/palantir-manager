## ADDED Requirements

### Requirement: Business users default to the project table after login

The system MUST use the business project table as the fallback destination after successful login for a business-only user.

#### Scenario: Business user logs in without an intended destination

- **WHEN** an account with the business role and without an administrator, finance, or tender role authenticates successfully without a saved intended URL
- **THEN** the system MUST redirect the user to `/objects/project`

#### Scenario: Business user logs in with an intended destination

- **WHEN** a business user was redirected to login from another protected route
- **THEN** successful authentication MUST return the user to that intended route instead of the project fallback

### Requirement: Other role landing behavior remains unchanged

The system MUST preserve the dashboard fallback for administrators, finance users, tender users, multi-role elevated users, and other accounts.

#### Scenario: Elevated or non-business user logs in

- **WHEN** an administrator, finance user, tender user, or other non-business account authenticates without an intended URL
- **THEN** the system MUST redirect the user to the existing dashboard route

#### Scenario: Business user directly opens the dashboard

- **WHEN** an authenticated business user directly navigates to the dashboard and retains dashboard permission
- **THEN** the dashboard MUST remain accessible and its navigation entry MUST remain unchanged
