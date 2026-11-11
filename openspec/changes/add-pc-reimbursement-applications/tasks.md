## Proposal
- [x] Inspect current PC routes, navigation, pages, permissions and shared draft/submission services.
- [x] Define scope, real entry points and retained capabilities.
- [x] Run strict OpenSpec validation (85 passed, 0 failed).
- [x] Obtain explicit proposal approval before implementation (2026-11-11; hide ordinary-user OA only, preserve approval engine).

## Implementation after approval
- [x] Add applicant PC entry/form and administrator-only workbench presentation/navigation with legacy PC link handling.
- [x] Reuse upload and manual draft services, short descriptions, amount totals, private attachment access and conflict feedback; no AI calls.
- [x] Add manual draft continuation and rejected current-version resubmission while preserving batch draft view behavior.
- [x] Feature tests: ordinary/admin/timebook/unauthorized boundaries, legacy URL applicant fallback, personal/CC retention, mobile contract retention, upload/submit/idempotency and draft conflicts.
- [x] UI tests: ordinary/admin navigation, form upload/save/submit/error/retry, short descriptions, resubmit, and retained notice/attachment actions.
- [x] Run focused tests, strict OpenSpec validation, Pint for changed PHP, and composer quality:gate.
- [ ] Install staged gate, create reviewed PR and verify merge before any deployment.
- [ ] When deploying, verify real PC applicant and admin sessions using dedicated test data; do not approve/reject real employee reimbursements.

## Validation evidence
- 2026-11-11: focused PHP 30 tests / 467 assertions; full PHP 568 passed, 5 pre-existing skips. Frontend tests and production build passed.
- Local Chrome used isolated SQLite: manual draft -> upload -> submit -> reject -> resubmit -> approve; no AI requests, no page errors, no production data changed.
- Final UI checks include nullable incomplete drafts and distinct applicant/OA navigation highlighting.
