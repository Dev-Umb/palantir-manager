# Delete reimbursement drafts

## Why
Users need to discard unwanted drafts from the Mini Program draft cards.

## What Changes
- Add a bottom-right trash icon with explicit confirmation, preserving continue-edit.
- Soft-delete only the owner’s unsubmitted draft with a revision check and transaction locks.
- Preserve invoices, batch links, submitted records and unrelated drafts; reject stale saves and uploads after deletion.

## Impact
Mini Program list, draft DELETE endpoint, draft model/service and deleted_at migration. Android/PC UI and publication are outside this change.
