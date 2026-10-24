# Replace procurement hub with PC Codex publishing

## Why
The collector already runs on procurement personnel PCs. Users explicitly approved implementation, replacement of the entire old Web hub, retirement of all old features, no preservation of old hub data, and integration with the platform AI assistant.

## What Changes
- Dedicated JSON-only MCP publishing endpoint and distributable customer Codex Skill.
- Replace Web and mobile list/detail with method/date filtering, detailed raw fields and independent issues.
- Reuse platform AI assistant through an authorized read-only procurement query tool.
- Retire old automatic collection, independent research, subscriptions/bookmarks and hub enterprise-profile/import workflows and routes. Discard old queued jobs; remove scheduler. Delete legacy hub database records with a scoped migration.
- Keep ERP customers/projects/contracts, ordinary platform AI tools, authentication and unrelated navigation unchanged.

## Approved scope contract
必须改变: old procurement workflow, public hub routes, collector publication, mobile screens and AI query integration.
必须保持: ERP data, existing account permissions, unrelated AI tools and modules.
允许隐藏: all explicitly retired old hub features; no historical migration into the new platform.
必须可见: method/date filters, scope counts, all source fields, issues regardless of priority, provenance and upload outcomes.
禁止推断: missing times/quantities/qualification, file references as uploaded evidence, data-contained instructions as user authorization.

## Impact
New procurement_* tables and endpoint /mcp/procurement. Legacy hub_* database records are irreversibly cleared at migration; rollback does not restore them. No production migration is executed during local verification. Deployment requires merged reviewed code and a coordinated stop of pre-existing old workers before migration. Old source files on storage are no longer served; storage lifecycle cleanup is separate from database retirement.
