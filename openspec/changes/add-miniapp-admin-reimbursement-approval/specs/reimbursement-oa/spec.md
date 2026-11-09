## MODIFIED Requirements
### Requirement: Distinct three client experiences
The system SHALL preserve each client's visual language and existing adjacent capabilities. Approval SHALL be available on PC and in the existing WeChat mini program for administrator accounts subject to existing current-node authorization. Android approval and mobile workflow configuration SHALL remain unavailable. Details SHALL be vertical, information/decision non-collapsible, items/progress/CC collapsible, and have no separate history component or actor switch. Workflow reviewers SHALL use dropdown multi-selection.
#### Scenario: View invoice
- **WHEN** the user selects an invoice eye action
- **THEN** the existing client preview opens, with single-file download and an independent all-invoice ZIP action.
#### Scenario: Ordinary mini program account
- **WHEN** a non-administrator uses the mini program
- **THEN** existing authorized submission, detail, attachment and notification features remain available without the administrator approval entry or actions.
#### Scenario: Administrator is not assigned
- **WHEN** an administrator views a version outside their current assigned node
- **THEN** no approval action is available and direct decision requests remain rejected by existing server authorization.

## ADDED Requirements
### Requirement: Administrator mini program reimbursement inbox
The mini program SHALL expose an OA approval entry for server-identified administrators in the existing workbench. Its default inbox SHALL contain only current reimbursement versions the actor can presently decide, with filtering applied before pagination and accurate totals. Other OA types SHALL be excluded.
#### Scenario: Actual pending decisions
- **WHEN** the administrator opens the inbox
- **THEN** future-node assignments, prior votes, blocked participants, historical versions and completed requests are excluded, and an empty result has an explicit empty state.
#### Scenario: Existing clients and queries
- **WHEN** a PC user or Android client uses an existing reimbursement query
- **THEN** its existing review scope, permissions and pagination semantics remain unchanged.

### Requirement: Single reimbursement decision from mini program
The mini program SHALL reuse authorized version details and the existing decision contract, with explicit approval confirmation, a required rejection reason, duplicate-submit prevention and server-confirmed results. It MUST NOT grant assignment overrides or change payment state.
#### Scenario: Successful decision
- **WHEN** an assigned administrator confirms approval or submits a valid rejection reason
- **THEN** the existing workflow commits the decision and audit records, and details and inbox refresh to server state while existing sequential and any/all voting rules remain intact.
#### Scenario: Stale or failed request
- **WHEN** another client advances the node, authorization fails, validation fails or the response is lost
- **THEN** the UI does not claim success or automatically resubmit, preserves entered opinion where safe, and provides refresh or correction appropriate to the server response.
#### Scenario: Identity changes
- **WHEN** the session expires, the user switches accounts or refreshed roles revoke administrator identity
- **THEN** cached administrator inbox data and actions are removed and stale responses cannot restore them.
