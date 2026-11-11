## Proposal and review
- [x] Inspect the supplied ZIP, existing navigation, support module and file storage services.
- [x] Record confirmed TOS/PostgreSQL storage direction, scope and capability mapping.
- [x] Run composer openspec:validate: 73 passed, 0 failed (2026-11-11).
- [x] Obtain proposal review approval before apply: 用户于 2026-11-11 回复「批准」。

## Apply after approval
- [x] Read relevant Laravel/Inertia skills, search versioned documentation, inspect schema and all affected callers.
- [x] Verify prototype metrics, export fields and error states against mapping; add any omitted capability before coding.
- [x] Implement additive PostgreSQL schema, models, scoped permissions and factories without touching unrelated workspace changes.
- [x] Implement private TOS upload/verified association/download, project ownership checks and retained originals.
- [x] Implement project CRUD, revision conflict protection and logical deletion.
- [x] Preserve material parser, single/double scope, source signatures, reviews, notes and workbook round trip.
- [x] Implement project/drawing/material UI and ordered secondary navigation; rename support titles and offline export labels without changing its contracts.
- [x] Test parser boundaries, malformed workbooks, missing versus zero, reimport, source changes and signatures at L1.
- [x] Test authorized sharing, denied direct access/file access, role boundaries, upload failure, duplicate import, conflicts and deletion at L2.
- [x] Test navigation/highlights, dialogs/Escape, save/retry/conflicts, review state and retained support actions at L3.
- [x] Verify ZIP collision naming and original bytes, and bounded memory handling for file exports.
- [x] Compare implemented pages to ZIP at desktop/mobile web widths; no lost controls or hidden status.
- [x] Run focused PHPUnit/Vitest, Pint for PHP changes, composer openspec:validate and composer quality:gate; report unrelated pre-existing failures separately.

## Separately authorized release
- [ ] If publishing is requested, install staged hook, commit only scoped changes, create PR and verify merge before deployment.
- [ ] Verify production PostgreSQL/TOS configuration and backup/rollback, 200 MB path, cross-browser shared state and authorized download; preserve production data and original objects.
- [ ] Report local tests, merge, deployment and online acceptance separately; do not archive without authorization.

## Local verification evidence
- OpenSpec strict validation: 73 passed, 0 failed.
- Full quality gate: PHPUnit 490 passed / 3 skipped (493 total); Vitest 323 passed; production bundle passed. Existing bundle-size warning remains.
- Isolated UTF-8 PostgreSQL 17: 11 module tests passed / 104 assertions; temporary server stopped after verification.
- TOS behavior uses fake storage for deterministic failure/retention tests; no live TOS upload or production mutation was performed.
- Browser: mock-API Chrome at desktop/mobile widths; project creation, module switching, return home, delete Escape cancellation; zero script errors and zero deletion requests on cancellation.
- No commit, PR, merge, deployment, production migration or OpenSpec archiving performed.

## Release branch verification
- User authorized deployment and clarified the target database is the existing Tencent Cloud PostgreSQL, not Volcano PostgreSQL. Files remain on TOS.
- Isolated branch based on main 08f8d71: full gate passed, PHPUnit 576 passed / 5 skipped, Vitest 369 passed, OpenSpec 85 passed; main and quotation assets built.
- New routes are included from support_library.php to preserve the production web.php and unrelated, not-yet-deployed login routes.
