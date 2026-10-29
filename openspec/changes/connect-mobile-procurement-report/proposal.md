# Connect procurement reporting in mobile clients
## Why
The approved mobile design exposes JSON procurement data, but current Flutter and Mini Program screens still read retired buyer/facts/summary fields.
## What Changes
- Implement approved list, counts, method/priority/date filters, detail, source actions and explicit AI draft handoff in both existing clients.
- Add a permission-aware workbench shortcut; retain reimbursement and four-tab navigation.
- Verify actual native rendering locally with isolated fixtures and record live/physical-device limits separately.
## Impact
Mobile UI and tests only. Reuse current authenticated procurement endpoints. No backend permissions, schema, publishing or deployment changes.
