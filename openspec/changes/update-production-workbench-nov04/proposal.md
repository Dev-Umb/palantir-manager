# Update production workbench from November 4 delivery

## Why
The supplied demo and design now prioritize follow-up work and align category lists with Excel grouping.

## What Changes
- Place four follow-up queues below summary totals and preserve the selected queue when returning from details.
- Integrate estimated totals and percentages into category rings; remove the redundant standalone comparison.
- Display suite quantities and disjoint Excel groups in category lists; keep hydraulic component units separate.
- Preserve database persistence, existing data, drafts, permissions, imports, exports and backups. Package JSON is not automatically imported.

## Impact
Only the embedded production interface and its regression tests change. No API, schema or permission changes.
