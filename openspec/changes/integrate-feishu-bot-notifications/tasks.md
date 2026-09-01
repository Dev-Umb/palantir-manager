## 1. Proposal and review

- [x] 1.1 Inspect canonical project statuses, existing reminder state machine, notification recipients, AI Run flow, and repository conventions.
- [x] 1.2 Define the scope contract, identity and delivery boundaries, retry/idempotency model, rollback, and evidence limits.
- [x] 1.3 Run `composer openspec:validate` and resolve strict validation errors.
- [x] 1.4 Obtain implementation authorization from the user; retain action-time confirmation for the real Feishu send.

## 2. Project payment reminder condition

- [x] 2.1 Replace payment-reminder eligibility with the two canonical overall statuses plus incomplete payment while preserving the existing natural-month state machine.
- [x] 2.2 L2 PHPUnit: both eligible statuses, unpaid and partially paid, missing processing-letter date, early status exclusion, paid resolution, existing recipients, other reminder types, repeat cadence and idempotency.

## 3. Feishu notification delivery

- [x] 3.1 Add environment-only configuration, user bindings, inbound events, notification deliveries, model factories and indexes.
- [x] 3.2 Add the token/message client with bounded timeout, retry, cache, error sanitization and HTTP-fake coverage.
- [x] 3.3 Dispatch idempotent after-commit deliveries from project and tender station notifications without affecting their transactions.
- [x] 3.4 L2 PHPUnit: configured/unconfigured, bound/unbound, success/retry/failure, occurrence deduplication and preserved station notification behavior.

## 4. Feishu read-only AI assistant

- [x] 4.1 Add verified, rate-limited callback handling with challenge support, event-id idempotency and fast acknowledgement.
- [x] 4.2 Add P2P text processing, binding/permission enforcement, Feishu conversation mapping, source-aware AI Run creation and async response.
- [x] 4.3 Add a read-only AI agent using existing authorized query tools; keep the Web agent unchanged.
- [x] 4.4 L2 PHPUnit: invalid verification, duplicate event, ignored message types/chat types, unbound/no-permission refusal, authorized query, data-scope preservation and async reply.

## 5. Local full-chain evidence

- [x] 5.1 Write isolated test records for projects, recipients and Feishu binding; run project-to-delivery flow with Queue/HTTP fake.
- [x] 5.2 Write an isolated callback event; run callback-to-AI-to-reply flow with AI/HTTP fake and assert persisted audit state.
- [x] 5.3 Run focused backend tests, Pint dirty formatting, directly affected regressions, strict OpenSpec validation and `composer quality:gate`.
- [x] 5.4 Review the final diff against required changes, preserved behavior, permitted concealment, required visibility and prohibited inference.

## 6. Real Feishu verification and deployment preparation

- [x] 6.1 Immediately before external sending, obtain user confirmation for the exact test recipient/message and use only process-level credentials.
- [x] 6.2 Send one clearly labeled test reminder, record the Feishu message ID, and ask the user to confirm receipt in Feishu.
- [x] 6.3 Prepare configuration, migration, worker/scheduler, rollback and smoke-test checklist; do not deploy without separate authorization.

## 7. Project payment reminder card and conversational verification

- [x] 7.1 Render payment reminders as interactive cards with project title, salesperson, project/payment status, progress, outstanding amount and authenticated detail URL.
- [x] 7.2 Preserve tender and non-payment project text messages and cover missing display values without inference.
- [x] 7.3 Run focused PHPUnit, Pint, strict OpenSpec validation and the applicable quality gate.
- [x] 7.4 Send one clearly labeled real card through the local full chain and verify it in Chen Hao's Feishu client.
- [x] 7.5 Send one project query from Chen Hao's bot conversation and verify inbound event, AI Run, outbound reply and visible client response without production writes.

## 8. Markdown AI reply card

- [x] 8.1 Render successful Feishu AI Markdown answers as interactive `lark_md` cards while retaining plain-text failure replies.
- [x] 8.2 Cover Markdown preservation, empty-answer placeholder and failure-message preservation with focused PHPUnit tests.
- [x] 8.3 Run Pint, focused PHPUnit, strict OpenSpec validation and the applicable quality gate.

## 9. AI processing reaction

- [x] 9.1 Add Feishu reaction create/delete client operations and persist the processing reaction lifecycle on inbound events.
- [x] 9.2 Add `Typing` only after P2P text, binding and permission acceptance; make creation failure non-blocking.
- [x] 9.3 Remove the bot-owned reaction after terminal reply and attempt cleanup when reply delivery is exhausted without duplicating replies.
- [x] 9.4 Cover accepted, rejected, reaction-failure and cleanup boundaries with focused PHPUnit tests.
- [x] 9.5 Run Pint, focused PHPUnit, strict OpenSpec validation and the applicable quality gate.

## 10. Group mention processing reaction

- [x] 10.1 Accept bound and authorized group text tasks only when mention metadata is present, and remove mention placeholders from the AI input.
- [x] 10.2 Add and remove `Typing` on the initiating group message while keeping the AI result private to the initiating user.
- [x] 10.3 Cover mentioned and unmentioned group boundaries with a callback-to-reaction-to-AI-to-card-to-cleanup end-to-end test.
- [x] 10.4 Run Pint, focused PHPUnit, strict OpenSpec validation and the full quality gate.

## 11. Group-source AI replies

- [x] 11.1 Send successful and failed group-origin AI replies to the inbound `chat_id` while preserving P2P `open_id` replies.
- [x] 11.2 Ignore malformed group events without a `chat_id` before creating an AI Run or reaction.
- [x] 11.3 Cover group destination, P2P preservation and malformed group boundaries with focused PHPUnit tests.
- [x] 11.4 Run Pint, focused PHPUnit, strict OpenSpec validation and the full quality gate.

## 12. Fifteen-day incomplete-payment repeats

- [x] 12.1 Preserve the first natural-month due date and change only subsequent incomplete-payment occurrences to a 15-day cadence.
- [x] 12.2 Cover the 14-day boundary, 15-day occurrence, same-cycle idempotency, first natural-month delay and unchanged non-payment reminder cadence.
- [x] 12.3 Run Pint, focused PHPUnit, strict OpenSpec validation and the applicable quality gate.

## 13. Last-payment-date eligibility and notification-center parity

- [x] 13.1 Require an approved project stage, positive numeric unpaid amount and valid `last_payment_date`; remove all date fallbacks.
- [x] 13.2 Cover both approved stages, early-stage exclusion, missing/invalid/not-due dates, zero debt, natural-month reset, 15-day repeats and idempotency.
- [x] 13.3 Prove that an existing due project-status notification creates a Feishu delivery for its business-owner recipient without changing notification-center rules.
- [x] 13.4 Run Pint, focused PHPUnit, strict OpenSpec validation and the full quality gate before deployment.
