## ADDED Requirements
### Requirement: Versioned reimbursement submissions
The system SHALL compute RMB totals in integer cents and preserve each submitted version, its files and workflow snapshot. Only an owner of a rejected current version may resubmit. Submission keys SHALL prevent duplicate creation.
#### Scenario: Rejected submission is corrected
- **WHEN** its owner resubmits valid changed items
- **THEN** a new immutable version begins at the first node and historical contents remain accessible under their own permissions.
### Requirement: Assigned sequential approval
The system SHALL support ordered nodes with any/all approval and reject-only-with-comment, using the authenticated current participant, never a supplied actor. Invalid participants SHALL block progress rather than be skipped.
#### Scenario: Concurrent or repeated decisions
- **WHEN** two decisions race or an actor repeats a decision
- **THEN** at most one decision per actor/node/version is committed, and a stale request cannot decide a later node.
### Requirement: Version scoped information and invoices
The system SHALL grant owners, assigned reviewers, authorized managers and triggered CC recipients access to the exact authorized version. New versions SHALL not inherit old CC grants.
#### Scenario: Unauthorized invoice access
- **WHEN** a user guesses another version's invoice URL
- **THEN** preview, download and ZIP access are denied consistently.
#### Scenario: Complete invoice archive
- **WHEN** an authorized user downloads all invoices
- **THEN** every invoice of that version is included with collision-safe Chinese names, or the entire request fails explicitly.
### Requirement: Distinct three client experiences
The system SHALL preserve each client's visual language and existing adjacent capabilities. Approval is PC only. Details SHALL be vertical, information/decision non-collapsible, items/progress/CC collapsible, and have no separate history component or actor switch. Workflow reviewers SHALL use dropdown multi-selection.
#### Scenario: View invoice
- **WHEN** the user selects an invoice eye action
- **THEN** a modal preview opens, with single-file download and an independent all-invoice ZIP action.
### Requirement: Notifications and CC
The system SHALL persist final-result and triggered-CC notifications, with read state and exact-version links. Android SHALL notify locally only after opening/resuming. WeChat result subscription is optional and cannot block submission.
#### Scenario: Notification delivery unavailable
- **WHEN** subscription is denied or WeChat delivery fails
- **THEN** the approval remains committed and the result is queryable in the application, with delivery failure recorded rather than reported as success.
