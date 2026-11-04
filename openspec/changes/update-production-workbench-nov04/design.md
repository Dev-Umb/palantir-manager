## Scope and authority
Use the November 4 ZIP's offline HTML and design document for visual and behavior changes. Retain the existing database adapter and lazy workbook loading.

## Capability mapping
| Existing capability | Updated entry or decision |
| --- | --- |
| Production totals | Same top summary and category cards |
| Follow-up three queues | Four queues immediately below summary; adds undrawn/unshipped |
| Standalone four-dimension comparison | Retired as specified; totals, status tonnage and percentages in category rings |
| Category shipment quick filters | Excel group tabs; shipping dropdown retained |
| Quantity details | Suite quantity also visible and sortable in lists; all hydraulic units remain in detail/export |
| Hydraulic tunnel field | Hidden only in category list; existing record/detail/export retained |
| Search, sorting, selected export, delete, undo | Same list controls |
| Save, drafts, version checks, backups, material demand | Existing server-backed behavior unchanged |

## Data boundary
Do not seed or restore the bundled business snapshot as part of the code update. Any authorized data replacement requires a separate live backup and validation.
