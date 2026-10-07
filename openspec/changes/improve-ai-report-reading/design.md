## Decisions

Reuse the existing HTML publication tool and sanitized iframe. Keep selection as a run ID plus artifact ID and resolve content from current run state so revisions update without duplicating report content. Opening is explicit; streaming updates must not reopen a reader the user closed. Clear selection when changing conversations or starting a new one. Keep the composer usable beside the desktop reader. Use a native modal dialog for enlarged and mobile reading, with Escape and focus restoration.

Do not introduce a query classifier, new tool protocol or independent generated site. The agent already has report-generation capability; adjust its instructions to choose a report for multi-part analysis and concise answers for simple questions. Respect explicit detail/text requests. Query output is retained in a collapsible section instead of deleted, including on failed runs. Old table-only conversations remain readable without fabricating an HTML report.

## Scope and preservation map

- 必须改变: HTML inline iframe becomes report card plus independent reader; repeated query visuals collapse by default.
- 必须保持: all query rows/columns, chart data, table filters/sorts, history, cancellation/retry, copy answer, choice/form/write-confirmation cards, existing upload entry points, authorization and sanitization.
- 允许隐藏: tables/charts behind 查询明细和图表; lengthy report body moves to the reader.
- 必须可见: answer, report title/open entry, data warnings, sources, failure messages and action cards.
- 禁止推断: no fabricated metrics, no treating limited samples as global totals, no permission expansion, no automatic conversion of historical responses.

| Existing capability | New carrier |
| --- | --- |
| HTML report body | Report card opens the sandboxed reader |
| Expand inline HTML | Enlarge reader / restore split view |
| Tables, rows, columns, sorting and filters | Expand 查询明细和图表 |
| Chart axes, tooltip and rows | Same chart in expanded details |
| Answer copy/retry, source links and warnings | Remain in the conversation; sources/warnings also appear with the selected report |
| Choice/form/create/update controls | Remain inline with current busy and confirmation rules |
| History and stored report revisions | Same persisted artifacts, resolved from current run state |

## Risks and evidence

LLM prompt changes guide report selection but cannot guarantee a live provider will call the tool. Unit/component tests verify the rendering contract and sanitizer; fake tool tests verify publication and retained query artifacts. A real model run and production deployment remain separate evidence. If report generation fails, show the existing error plus retained query results; do not claim report success.

## Rollback

Restore changed frontend and agent-instruction files. Artifacts keep their current schema, so no data migration or cleanup is needed.

## Six-month comparison test extension

The user confirmed a rolling six-month window for actual execution and collections, and endpoint receivables. Default endpoint is current query time, not a future end-of-day. Month subtraction must clamp month-end dates. Use recorded execution events such as ship_date and work_date; existing projects remain eligible. Do not treat project creation/update dates, current stages or last_payment_date as an event ledger. For current endpoint receivables, query unpaid_amount for the visible portfolio, including older outstanding projects, and disclose record freshness. Historical endpoints require appropriate snapshots or complete reconstructable ledgers.

Current local metadata includes shipment/work events and cumulative receivable records; it does not define a payment transaction ledger or financial snapshot history. Tests exercise real query/tool access against the existing metadata; they do not invent production tables or imply complete historical reporting exists. L2 tests cover admin grouping, event boundaries, ownership scopes, zero/null and period-vs-cumulative distinctions. L3 existing reader tests cover display, sources and warning visibility. The synthetic preview must explicitly show missing period collections instead of fake successful cash totals. No change to global financial formulas, permissions, database schema or deployed data.

## Report visual emphasis

The user requested more comparison tables/charts and less, simpler text. Prefer a small set of metric cards, a compact salesperson comparison, separate unit-consistent execution/balance bars, and a period-by-period table where dated evidence exists. Use one or two plain-language sentences for conclusions. Keep missing-data explanations concise and visible; do not convert unknowns into zero or imply a partial balance sum is a complete total. This changes agent presentation instructions, not report schemas or financial calculations.
