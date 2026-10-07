## Implementation

- [x] Inspect current page, artifact generation and permission boundaries; record approved scope and preservation map.
- [x] Validate OpenSpec before implementation.
- [x] Add report cards, right-side reader, responsive modal reading and selection lifecycle.
- [x] Collapse raw results while retaining adjacent actions, sources and failure visibility.
- [x] Update existing agent report-selection and grounding instructions.
- [x] Test report open/close, revisions, history/new conversation, mobile reading, HTML safety and retained query/action behavior.
- [x] Run strict OpenSpec validation, focused tests and final quality gate; record evidence boundaries.

## Evidence

- Isolated snapshot of the current dirty checkout; no production connection or credentials used.
- OpenSpec: 20 changes passed strict validation.
- Full PHPUnit suite: 126 passed, 1 skipped; full frontend suite: 147 passed (26 files). Production build passed.
- Browser preview uses actual AI page/artifact/layout components with explicitly labeled synthetic data. Desktop split reader and 390 × 844 fullscreen reader were visually checked; no mobile horizontal overflow; native Escape returned focus to the report entry.
- Live model report selection and production deployment have not been verified.

## Six-month comparison extension

- [x] Add rolling calendar-month dates, query-time endpoint, event/stock distinctions and source-evidence limitations to agent instructions.
- [x] Exercise real admin queries across salespeople and older projects using dated execution records, without substituting cumulative payments for period receipts.
- [x] Test dates at both boundaries, before/after the period, undated events, zero/null balances, no matches, and cross-owner denial for lists, groups and direct lookups.
- [x] Update the synthetic reading preview with the six-month comparison and unavailable receipt totals clearly labeled.
- [x] Run focused regression and final quality gate, then synchronize only this extension to the existing dirty checkout.

### Six-month verification evidence

Six new PHPUnit scenarios passed using the real QueryObjectRecordsTool and current metadata in isolated SQLite: inclusive execution dates on old projects, cross-sales grouping, original unpaid_amount with zero/null distinction, lack of transaction-level payment fields, missing dates/empty periods, and server-side cross-owner denial. Prompt checks also cover rolling month subtraction and month-end clamping. Full gate: 132 backend tests passed, 1 skipped; 147 frontend tests passed; strict validation and build passed. This is local test evidence, not PostgreSQL/live-provider or production verification. The preview uses clearly labeled synthetic data and shows period receipts as unavailable.

## Visual emphasis refinement

- [x] Prefer KPI cards, compact comparison tables and data-supported charts, with short plain-language notes.
- [x] Update the preview with execution and balance bars, monthly shipment comparison and concise limitations.
- [x] Verify prompt preservation, HTML chart safety, responsive preview and project quality gate; synchronize scoped changes.

Visual refinement evidence: 133 backend tests passed, 1 skipped; 147 frontend tests passed; strict validation and production build passed. A publication regression verifies static CSS comparison bars preserve labels, units and inline styling. The synthetic desktop preview shows four metric cards, two comparison charts and two tables with concise notes. No live model generation or deployment was performed.
