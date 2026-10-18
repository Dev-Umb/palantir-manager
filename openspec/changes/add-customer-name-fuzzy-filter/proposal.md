# Customer name fuzzy filters

## Why
Customer relations currently only support ID equality, preventing filtering by a partial customer name.

## What Changes
Add contains and not_contains only to relation fields targeting customer. Match current customer titles with literal, case-insensitive substrings. Exclude absent or unresolved customers from both fuzzy conditions; existing empty operators remain available. Preserve exact ID matching, other relation operators, visibility, AND/OR, pagination and exports.

## Impact
Ontology filter UI and query builder, focused backend/frontend tests. No schema, dependencies or permission changes.
