## Implementation
- [x] Inspect handoff, existing platform and approved scope.
- [x] Validate OpenSpec before implementation.
- [x] Implement dedicated schema, names, transactional writes and queries.
- [x] Integrate independent RBAC, navigation and responsive page.
- [x] Implement XLSX and authorized AI read-only tool.
- [x] Test target behavior, retained neighboring behavior and collateral boundaries.
- [x] Run strict validation, formatting and full quality gate.
- [x] Report local evidence and remaining migration/deployment prerequisites.

## Local evidence
- Full gate: 163 backend tests passed, 1 opt-in PostgreSQL concurrency test skipped; 2,080 assertions. All 183 frontend tests, 22 strict OpenSpec checks and production build passed.
- Isolated PostgreSQL: 10 module tests (156 assertions) and the explicitly enabled four-process concurrency test (18 assertions) passed.
- Browser: synthetic module-only user login, actual create and separate day/overtime totals verified; 390px responsive layout inspected.
- Initial standalone implementation did not deploy or import real ledgers. The production integration and release evidence below supersedes its deployment status. Real ledger migration still requires a source database and reconciliation first.

## Production integration and release (2026-09-18)
- User approved production source as the authoritative main baseline. `8858c86` captures the production source and aligns stale tests with existing behavior; 297 runtime source files matched production before release. Original dirty workspace was not changed.
- `853c62c` adds only the timebook module and integration points. The production AI history authorization is retained and extended only for timebook; query/source provenance and permission revocation are tested.
- Full integration gate: 168 backend tests passed (2,122 assertions), 1 opt-in test skipped; 200 frontend tests passed; 22 strict OpenSpec checks and production build passed. PostgreSQL module tests: 11 passed / 166 assertions; explicitly enabled four-process concurrency: 1 passed / 18 assertions.
- Private server backup directory: `storage/app/deploy-backups/timebook-20260918`. Pre-release code and full PostgreSQL archives created. Initial copy encountered existing directory ownership restrictions; prior code was restored and HTTP 200 verified before retrying with privileged, ownership-preserving copy. No global permission changes or seeding were used.
- Released at 2026-09-18 18:13 Asia/Taipei: migration batch 15, seven independent permissions installed for admin only, nine module routes active. Existing configuration and WebSocket build parameters retained. All 25 release source/manifest checksums matched local artifacts.
- HTTPS regression `REG-TIMEBOOK-20260918-181650`: 43 checks passed, including real login, module CRUD/restore/version conflicts/XLSX/audit, read-only and no-access boundaries, preserved project/customer/contract/notification/settings/hub/AI endpoints, and real queued AI query. AI Run `01a0b404-67a2-730c-b6e2-a1d268826138` completed with timebook provenance; access to its history was denied after permission revocation.
- Original 1,082 business records, 27 business objects, 73 pre-existing permissions, 197 original role grants, 17 user-role links, 16 active users, 10 roles and environment file all retained identical hashes. Only seven timebook admin grants were added. Synthetic record was soft-deleted with audit retained; temporary test users disabled and grants/roles removed.
- Eight application services healthy; AI backlog 0, failed runs 0. Full post-release database archive generated and checked for all three timebook tables. No real legacy ledger imported.
- GitHub push/update of remote main is awaiting explicit destination authorization after safety review blocked the initial push; no remote repository success is claimed.
