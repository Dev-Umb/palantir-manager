## 1. Proposal
- [x] Inspect existing upload, single-invoice recognition and attachment ownership constraints.
- [x] Inspect five private samples: page counts/text extraction and first-page visual layouts; do not infer invoice totals from names or page counts.
- [x] Obtain approval for the complete revised workflow.
## 2. Implementation after approval
- [x] Reconcile latest implementation/worktrees and map all existing upload, retry, preview, manual edit, download and resubmit entry points.
- [x] Implement bounded batch uploads and per-file/page recognition state with owner checks and idempotency.
- [x] Recognize multiple invoices and supported payment/receipt evidence, keeping source references and integer cents.
- [x] Support local preview during upload, source previews, red-dot text status and isolated retry.
- [x] Automatically add editable expense drafts without overwriting manual edits; handle duplicates and user-confirmed associations.
- [x] Update Android, mini-program and PC rendering/contracts without changing historical versions.
## 3. Verification and release
- [x] Test multi-file partial failure, same-page multi-ticket PDFs, rotated receipts, missing/ambiguous amounts and failed-payment evidence.
- [x] Test preserved manual edits, historical attachments, permissions, retry/submit idempotency, removal during recognition and stale results after logout.
- [x] Validate private samples without live business writes; report page coverage and unresolved recognition gaps.
- [x] Run OpenSpec validation, focused tests and quality gate.
- [ ] Merge PR before backend deployment and mobile upload.
- [ ] Verify actual mini-program and Android device preview/upload flows; distinguish uploaded builds from published versions.

Verification: Aimon processed all five private sample PDFs (44 pages). Lodging/fuel produced 10/8 amounts; toll produced 18 amounts with unresolved content retained; miscellaneous has unclear courier slips; courier sample yields three explicit 60.00 expense amounts with manual-payment/duplicate checks. This is extraction evidence, not full accounting acceptance. Mini-program WXML/WXSS compiled; Android debug APK built. Real-device picker/preview and deployed API acceptance remain pending. No production reimbursement data was created or modified.

## Entry-point regression correction
- [ ] Route the existing batch upload job through the same paged Aimon recognizer; preserve historical single-item batch payloads and categories.
- [ ] Generate one expense per recognized candidate, respecting 50-item/200 MB draft bounds without splitting one original across claims.
- [ ] Run five provided PDFs through actual signed upload, confirm, queue, generated draft, and reopen APIs using a dedicated test batch; do not submit approval or modify existing user batches.
