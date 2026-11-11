## ADDED Requirements
### Requirement: PC manual reimbursement application
The system SHALL allow users with the existing reimbursement submit permission to create and submit their own manual reimbursement from PC web, with brief expense descriptions of at most 20 characters, amounts, optional invoice dates, attachments and existing CC options. It MUST NOT initiate AI recognition or require invoice-content verification for this manual flow.
#### Scenario: Ordinary applicant submits from PC
- **WHEN** an authorized ordinary user fills a valid manual application and uploads an allowed attachment
- **THEN** the PC form shows the total, submits through existing versioned services, and shows the saved application without recognition requests.
#### Scenario: Upload or submission fails
- **WHEN** an upload or submission fails or a repeated submission is attempted
- **THEN** the form preserves entered information, reports the error and allows safe retry without duplicate applications.
#### Scenario: Unauthorized applicant
- **WHEN** an account without submission permission or a timebook-only operator accesses the form or submits data
- **THEN** server authorization denies the action without changing existing role permissions.

### Requirement: Separate applicant and approval workspaces
The system SHALL provide an applicant-facing PC reimbursement page and an administrator-only OA workbench. Ordinary users MUST NOT see the OA workbench, its management scopes or workflow settings in the PC UI. The existing shared approval authorization and workflow MUST remain unchanged. Their existing personal and CC access MUST remain available.
#### Scenario: Ordinary user navigates the reimbursement module
- **WHEN** an ordinary user opens the PC reimbursement entry
- **THEN** the page offers their own applications, CC records, drafts and notifications and displays no approval workbench or management controls.
#### Scenario: Workbench URL is entered directly
- **WHEN** a non-administrator requests the PC workbench or a legacy PC management-scope URL
- **THEN** the PC renders the applicant view using the personal scope, while existing JSON API authorization remains unchanged.
#### Scenario: Administrator opens the workbench
- **WHEN** an administrator opens OA approvals
- **THEN** existing management access, filters and workflow configuration permissions remain available and decisions still require current-node eligibility.
#### Scenario: Adjacent mobile and unrelated business capabilities
- **WHEN** mobile clients query existing permitted reimbursement scopes or a user navigates another authorized business module
- **THEN** existing response contracts, data permissions and unrelated navigation remain intact.

### Requirement: Cross-client manual drafts and rejected resubmission
The system SHALL reuse existing private manual drafts, revisions and submission idempotency for the PC form and SHALL support owner-only resubmission of a rejected current version. It MUST preserve attachments, source evidence and version history.
#### Scenario: Applicant continues a manual draft on PC
- **WHEN** the owner opens an unsubmitted manual draft created in PC or the mini-program
- **THEN** the PC form allows continued editing using the latest revision, while another user's draft remains inaccessible.
#### Scenario: Draft changed on another client
- **WHEN** saving or submitting uses a stale draft revision
- **THEN** the application reports the conflict and does not silently overwrite newer content.
#### Scenario: Rejected current application is resubmitted
- **WHEN** its authorized owner corrects and resubmits on PC
- **THEN** a new version starts through the existing workflow and old versions and original files remain available under existing access rules.
#### Scenario: Batch draft remains distinct
- **WHEN** a user opens an existing batch AI draft on PC
- **THEN** its existing read-only information and attachments remain visible with the mobile continuation path, and no manual-flow bypass is introduced.
