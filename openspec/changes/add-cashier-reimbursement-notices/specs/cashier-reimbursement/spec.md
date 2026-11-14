## ADDED Requirements

### Requirement: Cashier read-only OA access
The system SHALL provide an independently scoped cashier role with PC access to all reimbursement applications, versions, attachments and approval progress. Cashier access MUST NOT grant approval, rejection, workflow configuration, reimbursement submission, user administration or unrelated business access.

#### Scenario: Cashier signs in
- **WHEN** an active cashier signs in normally
- **THEN** the user reaches the existing OA approval page and can query all reimbursement statuses, view details and attachments, and read reimbursement notices

#### Scenario: Cashier attempts a write
- **WHEN** a cashier attempts approval, rejection, submission or workflow configuration directly through an API, including accidental assignment in an approval snapshot
- **THEN** the server denies the write and preserves all business state

#### Scenario: Existing access boundaries remain
- **WHEN** an ordinary applicant or an existing administrator accesses reimbursements
- **THEN** the applicant retains their own and CC views with OA hidden, while the administrator retains the existing workspace and current-node-only approval behavior

### Requirement: Final approval cashier notification
The system SHALL notify active cashiers only when the final approval completes successfully, using existing reimbursement CC and notice mechanisms and avoiding duplicate notices for the same version, recipient and event.

#### Scenario: Both approvals complete
- **WHEN** Wu Liqing and then Wu Quanming approve the configured nodes and the application reaches approved
- **THEN** active cashiers receive one final-approval notice linking to the reimbursement, with unread state visible in the existing OA approval page

#### Scenario: Intermediate or rejected application
- **WHEN** an application is submitted, an intermediate node approves, or any node rejects
- **THEN** no cashier reimbursement notice is created and existing non-cashier recipient rules remain in effect

#### Scenario: Pending application predates rollout
- **WHEN** an application submitted before rollout reaches final approved status after rollout
- **THEN** current active cashiers receive the notice without changes to its original approval nodes, expense data, amounts or attachments

#### Scenario: Historical completion and inactive recipients
- **WHEN** the feature is installed or a cashier account is inactive or no longer has the role
- **THEN** historical completed applications do not generate backfilled notices and inactive or removed cashiers receive no new cashier notices

### Requirement: Cashier account provisioning
The platform SHALL provision Yue Chune as a cashier account after checking for duplicates, with a temporary random password and existing password-change guidance, and expose the account in existing user administration.

#### Scenario: Initial provisioning
- **WHEN** no matching account exists and the approved change is deployed
- **THEN** one Yue Chune account is created with only the intended cashier role, and its role and effective permissions are verified without changing another account or binding
