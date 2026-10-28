## Architecture
Laravel owns reimbursement rules. Existing session authentication and Inertia/mobile request conventions remain unchanged. Store request identity separately from immutable submitted version payloads; JSON snapshots contain ordered nodes, named users, line items and upload references. A relational version access table grants owner/approver/triggered-CC access. Notifications reference exact versions. Upload rows own a private StoredAttachment and become bound to one request, allowing that owner's resubmission only.

## Concurrency and safety
Serialize submissions per owner and decisions per request in database transactions. Require a UUID submission key and expected version/node on decisions. Recheck current actor permissions, disabled/deleted participants, upload ownership and file integrity. Notification outbox rows are committed with state changes; queued delivery operates after commit. Never use client totals or actor IDs. Use authenticated attachment endpoints and private no-store responses. ZIP is assembled completely before returning it; delete temporary files after response or failure.

## UI contract
Three distinct design languages remain. PC-only approval and flow configuration. All clients offer version-bound invoice preview and downloads. Details are vertical; information and decision remain open; line items, progress and CC use independent collapsible panels. Historical versions are linked from information; no separate approval-record component.

## Operational defaults
RMB integer cents, 50 line items, 10 invoices per item, 20 MB each, PDF/JPEG/PNG only, 200 MB invoice total per submitted version, checked before accepting a submission so its complete ZIP stays downloadable. Four fixed initial expense categories. New permissions are additive; submit defaults to business/admin, management/configuration to admin. Approval is assignment-based plus authenticated non-timebook access; admin cannot override assignment. Workflow must be explicitly configured before submissions. Missing WeChat configuration leaves durable in-app notifications and a not-configured outbox delivery state, never fake success.

## Release gates
No deployment before merged PR. The mini program is currently outside Git; its exact source must be captured in a reviewable delivery before upload. WeChat template IDs/field mapping, real device permissions, file downloads and message receipt require runtime acceptance. Do not include secrets or unrelated dirty files in a commit.
