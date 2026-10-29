## 1. Review
- [x] User approves proposal and scope
- [x] Inspect current schema and validate OpenSpec before implementation

## 2. Backend
- [x] Add owner-scoped draft persistence and validated draft endpoints
- [x] Atomically connect successful PDF upload with a recoverable draft
- [x] Enforce revision conflicts, attachment ownership and idempotent submit lifecycle

## 3. Clients
- [x] APP and Mini Program autosave with state, retry and account guards
- [x] New-form recovery prompt and draft list continue-edit flow
- [x] PC owner-only draft list and read-only details
- [x] Preserve invoice review UI and rejected resubmission

## 4. Verification
- [x] Backend tests: incomplete form, upload recovery, authorization, conflicts, submit retries, late saves, legacy clients
- [x] APP/Mini tests: autosave, restore/new, errors, recognition cancel/confirm, logout and delayed responses
- [x] PC rendering and retained approval-list tests
- [x] UI review with drafts and existing invoice forms
- [x] Run strict OpenSpec validation and composer quality:gate
- [x] Report local evidence separately from release state
