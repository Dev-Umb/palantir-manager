# Design

## Status and requirement authority

Proposal only. The user authorized analysis and documents, and confirmed one shared library editable by all engineering users. Implementation, initialization, deployment and archiving are not performed. Detailed behavior is defined in [PRD](../../../docs/支架检索模块-PRD.md), with FR/BR identifiers and explicit source conflicts; test oracles and acceptance are in [test specification](../../../docs/支架检索模块-验收与测试用例.md).

## Platform integration

Use the existing Laravel/Inertia React stack, Layout, session and effective permission checks. Default grants add support-library.view/manage/export to engineering and admin only, using an additive installer. Keep the timebook_operator restrictive profile. Do not modify global object visibility, auth policy, business tables or AI tools.

Preserve the demo single-page information architecture. Styling uses existing platform tokens and module-scoped styles; all existing fields, buttons, notices, examples and valid exports have a mapped carrier. Remove misleading online-page claims of local-only/no-upload operation; retain them only in the separate offline snapshot where true.

## Data and transactional boundaries

Recommend a single support_libraries active pointer and append-only support_library_versions JSON snapshots. Payload preserves complete parts/frames/composition, sources, raw and normalized lengths, rules, and history. The natural-key and association validator runs on the whole candidate library. Avoid partially writable relational collections for this first whole-library workflow.

All writes use expected revision, conditional pointer update and transaction, with idempotent request identifiers. Import preview is server-owned, actor-bound, expiring, and version-bound; confirmation reauthorizes and rejects stale previews. Failed validation or persistence leaves the active version unchanged. Do not auto-merge conflicting edits. JSON hashes and metadata must not imply structural engineering verification.

## Calculation and exports

Server calculation is authoritative. Parse dimensions to positive integer millimetres; retain order and repeated segments/spans. Use all-grid bracing faces. Distinguish hard errors (invalid syntax, missing/ambiguous column, unsupported use/diameter pool, conflicting joint counts, overflow) from individual missing brace matches. Keep known, accounted and complete totals separately; null is not zero.

Weight profiles follow the supplied version: C14A14.535/C16A17.240kg/m, one channel per brace, M22×80 0.44kg/set. These are source-package estimating rules, not independently certified engineering standards. Preserve six-decimal rounding stages and one-decimal display using golden fixtures and independent arithmetic.

Result export revalidates criteria and revision rather than trusting client totals. The result remains one sheet/nine columns; database export remains two data sheets; template additionally contains instructions. Offline HTML preserves the standalone workflow and provenance but never embeds platform session data and never writes back automatically. Downloaded copies cannot be revoked; this is documented and bounded by export authorization. The offline JS engine is versioned and compared to the server engine with the same fixtures.

## Alternatives

An iframe/localStorage-only solution fails shared data and server authorization. A separate Python/Node service adds deployment and auth stacks. Generic object_records expands unrelated business governance. Fully normalized CRUD tables add snapshot complexity at the current52/30/58 scale; reconsider only if an approved future requirement needs larger indexed libraries or inventory relations.

## Migration, recovery and risk

Initialize only an empty module from the hash-verified supplied JSON, with 52parts/30frames/58composition and all unknowns retained. Do not infer a newer browser-local library or use old demonstration fixtures. Re-running initialization must not overwrite shared edits.

Before release include module tables in backup and test restore through golden queries. Once real writes exist, roll back code/entry visibility while retaining tables and snapshots; data rollback creates a new version. No destructive down migration after real use.

Risks: source documents contain stale historical statements, cross-language rounding drift, ambiguous natural keys, partial-result mislabelling, concurrent replacement, exported offline copy divergence, and surrounding uncommitted work. The PRD discrepancy register, centralized validation, transaction tests, golden fixtures and scoped changes address these without assuming unrelated work is deployed.

## Evidence boundary

Current source-package tests were rerun in a temporary copy:110 logic/DOM,12 simulated save,14 import/export checks passed. XLSX samples reopened independently. Local file browser access was denied by tool policy, so no real-browser visual or save-picker acceptance is claimed. New platform tests remain unexecuted because the module is not implemented.
