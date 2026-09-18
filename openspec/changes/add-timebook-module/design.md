# Design

## Dedicated operator follow-up
User subsequently requested visible user settings including both own login email and password changes. This supersedes the password-only settings restriction: reuse the existing settings page, current-password verification and unique-email validation, add settings navigation and allow settings.email. Preserve all business and AI restrictions and do not change the account's credentials during deployment.
User requested one dedicated management account and approved retaining own password changes. Use an explicit timebook_operator role as a restrictive access profile; do not infer this restriction for other timebook users. Filter effective permissions centrally and restrict authenticated web routes before rendering. Reuse existing password page and RBAC grants, without schema or dependency changes. Business navigation has only timebook and AI; password link remains outside business navigation. Credentials are generated only at provisioning, never committed. This follows the user's approved two-entry scope and does not change existing accounts.

## Scope
必须改变：工日簿原生模块、专用表、RBAC、AI 只读查询。必须保持：交接包记工口径及其他模块的路由、数据和权限。允许隐藏：原设密和独立登录。必须可见：录入、查询、汇总、XLSX、冲突和审计。禁止推断：工资、审批、多账本、自动合并姓名、AI 写入。

## Storage and isolation
Use timebook_workers, timebook_entries, timebook_entry_audits. Retain integer half_days and overtime_minutes, version and deleted flag. A partial unique index on worker_id/day for active rows enforces uniqueness on PostgreSQL and SQLite. Worker insertion, entry mutation and audit snapshot commit in one transaction. Do not add timebook to object_records, project workflow, production members or generic AI mutation allowlists.
Normalize names using NFKC, Unicode whitespace collapsing and full case folding. Project remains free text. Actors use platform user IDs; legacy audit actors remain unknown.

## Access
Declare timebook.view/create/update/delete/export/audit/ai.query in the existing permission catalogue. Read permission is required for every module action; each additional action checks its own permission. AI also requires ai.harness.view and checks current DB grants on every call. Existing business roles receive no implicit timebook access. Admin uses existing all-permissions convention. A focused permission installer adds only timebook permission rows and admin grants; it never invokes global metadata synchronization.

## Capability mapping
| Existing capability | New carrier | Verification |
| --- | --- | --- |
| Shared password | Platform session and module RBAC | anonymous and permission-denial tests |
| Free names and historical hints | Timebook name suggestions | normalization and failed-transaction tests |
| Date/days/overtime/project/note | dedicated entry form and table | validation and persistence tests |
| Duplicate and stale edit protection | unique index and version-checked transaction | duplicate, stale, restore-conflict tests |
| Date/name/exact worker filters, totals, summaries | shared TimebookQuery | whole-result and boundary tests |
| Edit/delete/undo/history | dedicated endpoints and full snapshots | state transition tests |
| Three-sheet XLSX | module export | workbook content/type and formula-literal tests |
| Phone and desktop | responsive module page | UI tests and build |
| AI query (new) | dedicated read-only query tool | authorization, revocation and aggregate parity tests |

## Alternatives
Embedding Python would retain separate sessions, deployment and backup paths. Generic object_records would require changing shared deletion, locking and export semantics. Dedicated module tables and existing Laravel services bound the change.

## Migration and rollout
Do not access or import a real SQLite ledger without a supplied source and authorization. No real ledger is included in the handoff. If one exists, migrate IDs, dates, versions, deleted rows and all audit snapshots with unknown legacy actors and reconcile per-worker totals before switching writes. Do not import password settings into platform accounts.
Module schema migrations are additive. Existing platform backup/recovery must include the new tables before release. Roll back application code with tables retained after real writes; destructive schema rollback is only for isolated empty/test databases. Local tests do not establish deployment, real-device or production restore success.
