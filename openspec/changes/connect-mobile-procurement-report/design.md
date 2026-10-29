# Approved design and scope
Reference: task procurement-mobile-design/index.html, approved by user 2026-10-29.

| Existing capability | New mapping |
| --- | --- |
| ProcurementPage and Mini tools procurement | Server-filtered cards and full matching totals |
| Old buyer/region/facts/reports fields | payload.unit/nature/subtype/quantity/material/contacts/terms/raw dates/issue/evidence |
| history switch (no supported backend contract) | Removed; current backend date range and priority filters |
| Existing detail | Full JSON fields and independent warning, source and AI actions |
| App external links / Mini copySource | Reuse only validated http(s) source URLs |
| AI assistant | Prefilled record ID + question; no automatic send or replacement of an active conversation |
| Workbench and personal tools | Procurement shortcut; reimbursement/create-project/find-project share one equal-width spaced row (user revision 2026-10-29). Preserve upload and AI shortcuts in a second row, notifications and navigation. |
| Authentication / visibility | Keep session epoch guards, clear data on logout, never grant a role locally |

API totals remain server totals; page data is never summed as total. Canceling a draft filter leaves applied filters unchanged. Invalid date range does not query. Return retains list query and scroll position. Failed/403 requests have explicit error state, distinct from empty success. Entire card is tappable, minimum target 44px, original values wrap. No new dependencies or upload/admin controls.
