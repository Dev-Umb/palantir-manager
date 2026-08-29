## 1. Requirement and contract baseline

- [x] 1.1 Review and approve the multi-contract scope contract, project-only write boundary, explicit-delete semantics, preservation of three append-only multi-attachment categories, and no-duplication storage decision.
- [x] 1.2 Capture current contract/project fields, permissions, direct CRUD, attachment authorization, aggregate/workflow/notification side effects, and dirty-worktree overlap before implementation.
- [x] 1.3 Run `composer openspec:validate` after approval and before implementation.

## 2. Backend project-contract synchronization

- [x] 2.1 Preserve all three contract multi-attachment fields and enforce effective global read-only behavior without removing its existing visibility, fields, records, permission keys, query, detail or export contracts.
- [x] 2.2 Implement `SyncProjectContracts` with bounded nested validation, stable-ID ownership checks, project-derived customer/project values, create/update/explicit-delete semantics, transaction locking, per-contract audit and one project-level side-effect refresh.
- [x] 2.3 Extend project create/update to accept multipart contract arrays, preserve absent existing contracts, clean only newly uploaded orphan files after failures, and return indexed validation errors.
- [x] 2.4 Extend selected project detail/edit props with authorized derived contract records and attachment display URLs; do not persist contract arrays in project payload.
- [x] 2.5 Enforce direct contract create/update/delete rejection while retaining contract list/detail/search/export and authorized attachment downloads.

## 3. Project contract user interface

- [x] 3.1 Add a reusable project contract editor for multiple new/existing details, three append-only multi-attachment categories, indexed errors and confirmed deletion.
- [x] 3.2 Add the editor to project create/edit and a read-only contract collection to project detail; keep project grid inline editing behavior unchanged.
- [x] 3.3 Submit file-bearing project creates as multipart POST and updates as multipart POST with `_method=PUT`.
- [x] 3.4 Make the contract table visibly read-only and remove create/edit/delete/inline-edit controls without hiding query, detail, attachment or export controls.

## 4. Regression evidence

- [x] 4.1 L2 PHPUnit target tests: multi-contract project create; mixed create/update/delete; attachments; absent-versus-explicit deletion; aggregate/workflow/notification/audit results; atomic rollback and orphan cleanup.
- [x] 4.2 L2 PHPUnit boundary tests: cross-project IDs, project visibility, read-only users, direct contract CRUD 403, existing contract data without migration, contract read/detail/export/attachment, project ordinary updates and non-contract finance behavior.
- [x] 4.3 L3 Vitest: project contract editor interactions, attachment retention/append, multipart method spoofing, indexed errors, project details and contract read-only controls.
- [x] 4.4 Run focused `composer test:narrow -- <test-file-or-filter>` and `npm run test:ui -- <test-file>` during iteration.
- [x] 4.5 Run `vendor/bin/pint --dirty --format agent`, `npm run build`, `composer openspec:validate`, and `composer quality:gate`; report each evidence state separately.
