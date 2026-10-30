## ADDED Requirements

### Requirement: Confirmed draft deletion
The Mini Program SHALL display an accessible trash button at the bottom right of draft cards, alongside continue-edit, and require explicit confirmation before deletion.

#### Scenario: Cancel or fail
- **WHEN** confirmation is cancelled or the request fails
- **THEN** the card remains and editing is preserved, with failure feedback when applicable

#### Scenario: Confirm deletion
- **WHEN** the owner confirms deleting the current draft revision
- **THEN** the card disappears after server success and pagination and batch counts refresh

### Requirement: Preserve business boundaries
The server SHALL soft-delete only an owned unsubmitted draft, using the existing user and draft lock order and revision validation.

#### Scenario: Invalid or stale access
- **WHEN** another user, a stale revision, or a submitted draft is used
- **THEN** deletion is rejected without changing the draft or invoices

#### Scenario: Deleted draft is reused
- **WHEN** an old editor saves, uploads, or submits a deleted draft, or batch processing runs again
- **THEN** the draft does not reappear and existing attachments and submitted records remain intact

### Requirement: Visible expense removal
Expense cards SHALL use the same red trash icon style while preserving existing removal behavior and upload/submission locks.

#### Scenario: Remove one expense
- **WHEN** the user removes an expense
- **THEN** remaining amounts and attachments remain intact and totals recalculate
- **AND** the separate attachment removal control remains available
