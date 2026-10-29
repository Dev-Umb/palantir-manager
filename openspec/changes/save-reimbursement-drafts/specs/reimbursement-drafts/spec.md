## ADDED Requirements

### Requirement: Save recoverable drafts on PDF upload
The system SHALL persist an owner-scoped draft with the current incomplete form and uploaded PDF before reporting a successful draft-enabled upload.

#### Scenario: Leave immediately after upload
- **WHEN** a user successfully uploads a PDF and leaves before recognition or submission
- **THEN** the draft and attachment can be restored by that same user
- **AND** no approval or notification is created

#### Scenario: Save subsequent edits
- **WHEN** a draft owner edits fields, confirms recognition or changes attachments
- **THEN** the client automatically saves those changes and displays truthful save status
- **AND** a failed save offers retry without clearing entered content

### Requirement: Restore drafts through new and list entry points
The system SHALL offer recovery of the current user's drafts when starting a new reimbursement and through the reimbursement list.

#### Scenario: Choose to start another reimbursement
- **WHEN** an owner with existing drafts chooses new reimbursement
- **THEN** the existing drafts remain available and are not overwritten

#### Scenario: View drafts
- **WHEN** an owner opens My drafts
- **THEN** the list shows unsubmitted drafts with updated time and a view entry
- **AND** APP and Mini Program allow continuing editing while PC provides read-only details
- **AND** drafts do not contribute to approval counts

### Requirement: Preserve privacy and concurrent edits
The system MUST restrict draft content and attachments to their owner and prevent stale writes.

#### Scenario: Another user requests a draft
- **WHEN** another user including an approval administrator requests the draft or its files
- **THEN** access is denied

#### Scenario: Stale revision or account response
- **WHEN** another session has saved a newer revision or the client account has changed
- **THEN** stale updates do not silently overwrite the draft or populate the new account's form

### Requirement: Submit drafts through existing formal rules
The system SHALL apply existing validation, authorization and idempotency when submitting a draft and atomically mark it submitted only after success.

#### Scenario: Submission fails or result is uncertain
- **WHEN** formal validation fails or the client times out
- **THEN** failed submissions preserve the draft and uncertain submissions can be retried idempotently

#### Scenario: Successful submission and delayed autosave
- **WHEN** a draft is submitted successfully and an old autosave request arrives
- **THEN** the draft remains absent from active drafts and the old request cannot recreate it

#### Scenario: Existing client and rejected resubmission
- **WHEN** an old client submits without a draft or a restored rejected resubmission is submitted
- **THEN** existing submission behavior and rejected-version checks remain enforced
