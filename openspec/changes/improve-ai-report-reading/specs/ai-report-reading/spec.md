## ADDED Requirements

### Requirement: Separate report reader

The assistant SHALL present each HTML artifact as a named entry that opens a separate right-side reader on desktop and a fullscreen reader on small screens. The reader MUST preserve the current HTML sanitization, empty sandbox and restrictive CSP.

#### Scenario: Open and close a report

- **WHEN** the user opens a report card
- **THEN** the matching report is readable while desktop chat remains usable, and closing returns focus to the entry without losing the conversation or draft

#### Scenario: Switch report or conversation

- **WHEN** the user selects another report, receives an updated revision, changes conversation or starts a new conversation
- **THEN** the reader shows the selected current revision, or closes for a different conversation, and never displays a stale report from a previous conversation

#### Scenario: Mobile or enlarged reading

- **WHEN** the reader opens on a small screen or the user enlarges it
- **THEN** it uses fullscreen modal reading with a visible close action, keyboard dismissal and focus containment

### Requirement: Retain query details without overwhelming the conversation

The assistant SHALL group raw query tables and charts under an expandable entry, retaining every existing field, row, sorting/filter control and chart interaction. Answers, warnings, sources, failures and action cards MUST remain visible outside this collapsed section.

#### Scenario: Report and intermediate queries

- **WHEN** a run includes tables, charts and an HTML report
- **THEN** the report entry and answer are visible and the original query visuals are accessible by expanding details

#### Scenario: No report or failed generation

- **WHEN** a historical or failed run has query results but no usable HTML report
- **THEN** the query details remain accessible and any failure message is visible without inventing a report

### Requirement: Select readable output for the question

Agent instructions MUST call for concise direct answers to simple questions and HTML reports for report-style analysis after querying real authorized data, while respecting explicit plain-text and detail-table requests. Reports MUST state available data scope, units, sources and limitations, and MUST NOT extrapolate limited rows into full totals.

#### Scenario: Report analysis

- **WHEN** the user requests a multi-part business analysis or report without requiring plain text
- **THEN** the agent is instructed to query first, publish a structured HTML report, and provide a short chat summary without duplicating full tables

#### Scenario: Simple or explicit detail question

- **WHEN** the question only needs a number, a short explanation, or explicitly requests details or text
- **THEN** the agent is instructed to use the requested concise or tabular answer rather than generating an unnecessary report

### Requirement: Recent six-month salesperson comparison

The assistant MUST support testing administrator comparison of salesperson project execution and collections during the rolling six calendar months ending at the current query time in Asia/Taipei, with receivables as of that endpoint. The report MUST display exact dates and source freshness. Period events MUST be filtered by actual business event date, including events on projects created before the period. Sales ownership MUST come from the available verified business ownership field and MUST NOT be inferred from event creator or current operator. Existing server-side visibility remains mandatory.

#### Scenario: Known period and active older projects

- **WHEN** the user asks for the recent six months on 2026-09-13
- **THEN** the report identifies 2026-03-13 through the query time on 2026-09-13, includes in-period shipment/work events even on older projects, and excludes out-of-period or undated events from measured period totals

#### Scenario: Current balance versus period collections

- **WHEN** only cumulative paid_amount, last_payment_date and current unpaid_amount are available
- **THEN** the report must not claim cumulative paid_amount is period cash received; current unpaid_amount is labeled as the current ledger balance with available update time, never reconstructed as a historical balance from contract amount minus collections

#### Scenario: Missing evidence and zero values

- **WHEN** payment transactions or historical snapshots are unavailable, or some event dates/amounts are missing
- **THEN** the affected metric displays an evidence limitation rather than zero, and actual numeric zero remains distinguishable from missing data

#### Scenario: Salesperson attempts a cross-owner comparison

- **WHEN** an ordinary salesperson requests another salesperson's project or its linked execution records
- **THEN** server-side access scope excludes those records, including direct record lookup, filters and grouped summaries

### Requirement: Visual comparisons with concise plain language

Analytical reports SHALL prioritize metric cards, compact comparison tables and useful charts over prose. Each chart MUST use supported data with explicit units and consistent scales. Unknown values MUST NOT be plotted as zero. Brief plain-language notes SHALL describe the main difference or next action without repeating every table value. Required period, source and missing-data disclosures remain visible and concise.

#### Scenario: Salesperson comparison has usable data

- **WHEN** execution and current balance data are available for multiple salespeople
- **THEN** the report presents comparison tables and separate charts with their own units, followed by short conclusions instead of long narrative sections

#### Scenario: Receipts cannot be verified

- **WHEN** receipt transactions are missing
- **THEN** the receipts comparison states that data is missing without generating a misleading zero-valued chart
