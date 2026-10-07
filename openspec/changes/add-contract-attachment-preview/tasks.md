## 1. Proposal and review

- [x] 1.1 Inspect current checkout, newer local contract implementation and existing Android design; document evidence drift.
- [x] 1.2 Define scope contract, capability map, UI behavior, security boundaries, client dependencies and acceptance criteria.
- [x] 1.3 Pass `composer openspec:validate` and record proposal quality-gate evidence (15/15 strict validation passed; existing PHPUnit gate blocked by missing `users.deleted_at`, 6 passed / 55 errors; frontend/build not reached).
- [x] 1.4 User explicitly approved implementation including PDF.js; added spaced attachment-card requirement for desktop/mobile.

## 2. Implementation baseline

- [x] 2.1 Confirm actual deployment and integration revision; preserve the dirty checkout and prepare an isolated implementation workspace.
- [x] 2.1a Resolve or verify absence of the current `users.deleted_at` test-schema mismatch on the selected integration baseline before relying on its quality gate.
- [x] 2.2 Reconfirm attachment fields, array/index semantics, historic single-file compatibility and all project/contract entry points against that baseline.
- [ ] 2.3 Confirm supported browser/client versions; coordinate native/WebView authentication and repository with the Android work item if client delivery is required in this batch.
- [x] 2.4 Read relevant Laravel/Inertia skills and version-specific docs; validate OpenSpec before implementation. Select and lock the approved PDF.js version after a real-document compatibility check.

## 3. Backend preview

- [x] 3.1 Reuse attachment authorization/path resolution without changing the existing download contract or upload semantics.
- [x] 3.2 Add protected metadata and inline-content routes limited to the approved fields; validate MIME, indexes, HEAD/Range and error responses.
- [x] 3.3 Add derived preview descriptors while preserving original payload/display/download consumers and original array indexes; avoid per-attachment file reads on list requests.
- [x] 3.4 Add focused PHPUnit feature tests for PDFs/images, permission denial, historical/index boundaries, invalid MIME, missing files, sessions and byte ranges; run the changed test file.

## 4. Web reading experience

- [x] 4.1 Build an accessible reading surface: mobile full screen, desktop large dialog, correct title, close/back and download.
- [x] 4.2 Implement lazy PDF rendering with worker/assets, pages, zoom, fit width, cancellation and bounded resource cleanup.
- [x] 4.3 Implement image zoom/pan/reset and clear loading/error/retry/authentication states.
- [x] 4.4 Connect project contract details, editing history and contract ledger/detail; preserve pending forms and distinguish saved files from upload selections.
- [x] 4.5 Add and run focused Vitest interaction tests including fast switching, stale results, back navigation, upload failure and unchanged statement/unrelated attachment behavior.

## 5. Integration and delivery evidence

- [x] 5.1 Run preserved upload-append, contract-state, project-sync, authorization and original-download PHPUnit regressions on the implementation baseline.
- [x] 5.2 Validate real non-sensitive PDFs/images, multipage scans, 20 MB files and failures in desktop browsers; record actual rendering evidence separately from mocked component tests.
- [ ] 5.3 Verify mobile layouts and Android Chrome/iOS Safari on actual target devices; record readability, navigation, image gestures, weak-network states and form preservation.
- [ ] 5.4 If a WebView/native client is included, integrate its approved auth and back behavior and complete a separate real-device test. Otherwise explicitly leave native delivery pending.
- [x] 5.5 Run PHP Pint when PHP changes, OpenSpec strict validation and `composer quality:gate`; install the staged gate before any commit, and do not bypass failures.
- [x] 5.6 Record changed scope, tests, unresolved devices, commit/deployment state and rollback; production writes/online mutation regressions only under their opt-in authorization.
- [ ] 5.7 After authorized deployment verify an actual uploaded attachment path with an authorized user, separately from local tests. Do not archive the change without authorization.


## Implementation evidence

- Approved feature implemented in `/private/tmp/palantir-attachment-preview`; original workspace runtime files preserved. No schema migration or new upload limits were introduced.
- Focused L2: 41 tests / 596 assertions passed, including new preview tests plus original project-contract upload and business-state regressions.
- Focused L3 includes attachment cards, reader/error states, PDF page/zoom boundaries, historical upload submission and browser-history interception; all passed.
- Actual local Chromium verified desktop file cards, protected JPG reading and zoom, real two-page Chinese PDF rendering, phone-size JPG/PDF, browser back, unsaved project remark AND newly selected upload retention, table attachment tray and 320 px width without overflow. Browser page errors: 0. Test files and users are confined to an isolated local SQLite database.
- Physical Android/iOS devices and a native client were not available and remain unverified; desktop mobile viewport checks do not satisfy native client acceptance. No production attachment was opened by a recipient in this run.
- Screenshot/structured evidence: `/private/tmp/palantir-preview-demo/`. Full gate and final delivery artifacts are recorded below after completion.


## Final local delivery (2026-09-08)

- `composer quality:gate` passed: OpenSpec 24 changes; backend 201 tests / 2394 assertions; frontend 33 files / 191 tests; production build passed. Pint and whitespace checks passed. Existing large-chunk build warnings remain non-blocking.
- Final browser coverage additionally includes PNG loading after a simulated network failure/retry, an exactly 20 MB valid PDF, and a damaged PDF with a visible error state. Final browser page errors: 0. Screenshots were visually inspected; PDF page 2 content matches the page indicator.
- Durable delivery directory: `outputs/contract-attachment-preview-20260908/` in the original workspace. `source-changes.zip` contains changed source/tests/specs without dependencies, secrets, databases or business files. `implementation.patch` is against `7dfb85a`.
- `live-context.patch` adjusts only patch context for the read-only production source snapshot: existing `ObjectRelations` additions and the stylesheet tail differed. The patch passed `git apply --check` against that snapshot; this is NOT production deployment, nor a full runtime gate for all production source. `baseline-fingerprints.json` permits repeat preflight; rebuild from current production-compatible sources before a future release, never deploy this older baseline's whole frontend build.
- At the local-delivery checkpoint, no commit, push, merge, deployment, production writes or change archival had been performed. Native client integration and physical Android/iOS validation remain pending under the separate client work item.


## Authorized production delivery (2026-09-08)

- User explicitly requested production deployment followed by actual browser clicking. Production-compatible candidate was built from a fresh complete production frontend snapshot; 18 runtime files were applied with source fingerprint guards. Existing unrelated source, permissions, download routes, and old hashed assets were preserved.
- Release `attachment-preview-20260908-r2` succeeded after a first attempt automatically rolled back on a write to an identical read-only historical asset. The corrected publisher reuses hash-identical assets without changing their permissions. Code/assets and validated PostgreSQL backups are under `/var/www/palantir/storage/app/deploy-backups/`.
- Public resource inspection detected that this Nginx installation serves `.mjs` as `application/octet-stream`. Release `attachment-preview-20260908-r3` preserves the original PDF worker module bytes and exports while emitting a `.js` asset through Vite output naming. A build integration regression validates its extension, reader URL reference, and `WorkerMessageHandler.setup` export. Complete implementation-baseline quality gate passed again (201 backend tests / 2394 assertions, 191 frontend tests, OpenSpec and production build). The actual production-source candidate separately passed 189 existing frontend checks, 11 attachment feature tests / 120 assertions, and the new worker build regression.
- The production snapshot contains older backend tests: its 17 existing failures/errors are identical before and after this patch (including missing deployment/demo fixtures and stale business-rule expectations). This is not a claim that the production snapshot's full backend suite passes.
- Release r3 completed after the backup-only preflight stopped because the server has no Node binary; no runtime changes had occurred at that stop. Node syntax/build checks had already passed locally. Resume rechecked source fingerprints and the verified database backup, then installed only `vite.config.js` and prebuilt assets.
- Post-release verification preserved all 1,082 object records, 31 accounts, attachment metadata, roles, permissions, and object definitions byte-for-byte at the row hash level. No migration, business-record write, test-account creation, or attachment upload was performed. Nginx and PHP-FPM are active.
- Read-only production service verification resolved all 8 existing attachments across 3 contracts (JPG and PNG). Existing production contracts contain no PDF sample. Public login/build resources and unauthenticated attachment denial are checked separately in delivery output JSON.
- Actual production browser clicking remains PENDING: CUA twice reported that the Mac is locked and cannot automatically unlock. The user was asked to unlock while independent deployment work continued. Neither API/service checks nor local desktop/mobile Chromium evidence satisfy this pending browser acceptance. Physical Android/iOS and native/WebView delivery also remain pending. No change archival, commit, push, or merge performed.
