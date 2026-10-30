## ADDED Requirements
### Requirement: Personal notification center
The mini program SHALL show existing reimbursement approval notices and AI-created draft results in My notification center, retaining project and procurement notifications and owner-only access.
#### Scenario: Generated draft
- **WHEN** background recognition generates a draft
- **THEN** its owner can open the corresponding result from the notification center; submitted results link to their application instead
### Requirement: Unread AI drafts
An AI-created draft SHALL show a small red dot above its title until its owner first opens it, and SHALL retain the source label AI批量识别创建 after reading.
#### Scenario: Read without editing
- **WHEN** the owner opens a new AI draft
- **THEN** the unread marker clears persistently without changing its payload, revision, review flags or update order
#### Scenario: Another account
- **WHEN** another account requests the draft or notification list
- **THEN** the draft remains inaccessible and its read state unchanged
### Requirement: Quiet draft form
The mini-program batch draft form SHALL omit the repetitive AI header and issue summary while preserving editable invoice fields, file access and submission validation.
#### Scenario: Review on submission
- **WHEN** a user submits a draft with unresolved invoice concerns
- **THEN** existing confirmation and validation still apply; simply opening the draft does not approve it
