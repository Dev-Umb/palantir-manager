# Restore supplied procurement report UI

## Why
The live overview diverges from the customer HTML: it loses priority ordering, source metadata, obvious teal links and the same-page article navigation.

## What Changes
- Restore six report columns, teal links, light teal header, alternating rows and full inline articles matching the supplied HTML.
- Keep pending entries in a separate visible section; maintain source detail and uncertainty.
- Sort authorized matching records by priority, newest upload batch, then original insertion order before pagination.
- Keep statistics, method/time/search filters, AI links, standalone detail route, account reminder and existing permissions.

## Impact
Procurement React views and scoped query ordering only. No schema, ingest, authentication, ERP or data deletion changes. The customer explicitly requested this restoration.
