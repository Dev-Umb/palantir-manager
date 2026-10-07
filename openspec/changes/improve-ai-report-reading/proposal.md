## Why

AI data queries append every intermediate table and chart to the conversation, making reports difficult to read. The user requested report-oriented HTML output and selected a separate right-side reading area like the web version of GPT.

## What Changes

- Show HTML artifacts as named report cards that open in a right-side reader; use a fullscreen reader on small screens and offer enlarged reading on desktop.
- Group query tables and charts under an expandable details entry, retaining sorting, filtering and all rows. Leave answers, warnings, sources and action cards visible.
- Instruct the existing agent to publish HTML for analytical reports after querying, use concise text for simple questions, and respect explicit requests for text or detail tables.
- Preserve existing HTML sanitization, CSP, sandbox, conversation persistence, tools, permissions, routes and record-confirmation actions.

## Capabilities

### New Capabilities

- `ai-report-reading`: Focused report reading with accessible underlying query results.

### Modified Capabilities

- None.

## Impact

AI page, artifact renderer, scoped CSS and agent instructions. No schema, dependency, route or authorization change. Historical HTML reports use the new reader without migration. No automatic conversion or deletion of existing artifacts.

## Approval

The user selected the proposed right-side reading experience on 2026-09-13: “像网页GPT那样右侧独立阅读区”. This authorizes implementation of the discussed display change; deployment is not included.

The user also authorized the test scenario: administrator comparison across salespeople over the recent six months, using actual execution and receipts during the period and receivables at the endpoint. This extension adds test coverage and explicit agent evidence rules, not a new payment ledger or historical data backfill.

The user further requested more comparison tables and charts, with concise plain-language descriptions. The visual emphasis refinement is authorized within the same report-reading scope.
