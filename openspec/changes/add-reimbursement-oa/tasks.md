## 1. Backend
- [x] Add schema, submission snapshots, ownership and scoped access.
- [x] Add sequential approval, countersign/or-sign, CC and idempotent state changes.
- [x] Add authenticated upload, preview, individual download and complete ZIP.
- [x] Add workflow configuration, notifications and queued WeChat delivery.
## 2. Clients
- [x] Add PC list, vertical details and dropdown workflow editor.
- [x] Add mini program entry, form, version detail, CC and notifications.
- [x] Add Flutter entry, form, version detail, CC and foreground local notifications (native build/device validation remains below).
## 3. Verification and release
- [x] Run focused authorization/state/file tests and retained-capability tests.
- [x] Run React and mini program tests, template compilation, and backend/frontend quality gate.
- [x] Restore Flutter/Android SDK and run Flutter analysis, tests and debug APK build.
- [x] Walk through Android emulator and local PC submission, reject/resubmit, preview, ZIP and foreground notification flows.
- [ ] Complete physical-device/account-switch and real WeChat subscription delivery acceptance.
- [ ] Create reviewable PR and capture mini program sources.
- [ ] Verify merge before backup/deploy; perform device and notification acceptance.

## Current verification evidence
- Backend/frontend quality gate passed on 2026-10-28: 441 PHPUnit passes, 2 skips; 294 Vitest passes; production build and 64 OpenSpec checks passed, including the safe notification retry test.
- Mini program: 42 Node tests passed; all four reimbursement WXML and WXSS files compiled using WeChat Developer Tools. Source is captured under mobile/wechat and synchronized to the existing local project; no upload or release performed.
- PC: isolated SQLite fixture with real login, invoice modal and approval write-back verified in browser; information/actions remain expanded and the other three sections collapse independently.
- Flutter 3.47.4 / Dart 3.13.3 restored from the official tagged source and engine package; Android 36, NDK 28.2, emulator and Gradle 9.3.1 restored with archive checksum verification. `flutter analyze --no-pub` has no issues; all 108 Flutter tests passed with the existing locked dependencies. Persistent SDK copies live under /Users/umb/Developer/palantir-sdks; local Android properties now reference those paths. Android emulator boots successfully. Debug APK builds pass, including a local-only API build. Maven TLS download failures were resolved by caching the exact official artifacts; no application dependency upgrade was retained.
- Review fixes: preserve unread filtering across notice pagination; align mobile canonical money validation with the server; reject empty mini-program expense lists and discard stale-account submissions; constrain workflow checkboxes to prevent wrapped labels. Focused backend test: 11 tests, 119 assertions.
- PC workflow dropdown and corrected checkbox layout verified in the browser. Mini-program form layout and real amount-input total were visually checked in a temporary isolated project using the same formal page source and fictional API responses. The existing project automation runtime had a screenshot mismatch; its temporary request mocks were restored. Mini-program live backend/WeChat delivery acceptance is still outstanding.
- Android emulator verified real local login, notification permission denial without blocking business, invoice upload, submission, PC rejection, V2 resubmission from the first node, image preview close, ZIP save with byte-identical contents, PC final approval and foreground system notification. Re-entering the app retained one unchanged notification record. Source fixes also cover refreshed list navigation after form completion and gesture-safe bottom spacing (regression tests included).
- PR/release remains pending: physical-device and real WeChat acceptance is outstanding, and this working tree includes pre-existing uncommitted storage/authentication/mobile prerequisites. Do not stage or ship unrelated changes. Real WeChat template delivery and full Android account-switch checks remain pending. Local permission denial and later permission enablement were verified.
- For explicitly failed/not-configured WeChat deliveries, operators may use `php artisan reimbursements:retry-notice <id>` after correcting configuration; sent/denied/unknown outcomes are not automatically replayed.

## Visual correction after prototype comparison
- [x] Restore the approved PC list hierarchy: title/subtitle, status tabs, one filter/table panel, owner avatars, shaded table heading and emphasized titles/amounts. Preserve scope, date-range filtering, permissions, pagination and existing navigation.
- [x] Derive pending count from the authorized server query before status filtering and pagination; label it 待审批, not a claim that the viewer can personally act on every record.
- [x] Repeat local three-client visual walkthrough of list, form, detail, CC/notice, PC workflow and preserved navigation; physical-device and authenticated mini-program integration acceptance remain separate. Earlier functional walkthroughs do not establish prototype visual parity; the user rejected the initial PC list styling.

- 2026-10-29 visual correction: mobile cards now show number/status, title/type/date and emphasized amount; Android dates are localized and ExpansionTile divider artifacts removed; mini-program persistent field labels, top create action, single-line pagination and non-growing invoice buttons fix observed rendering defects. PC detail typography/timeline and centered sequential workflow cards aligned to the approved reference.
- Current checks: 110 Flutter tests and analysis passed; debug APK built/installed and actual form scrolling, detail folding, CC empty state and notices inspected. 44 mini-program tests and four WXML/WXSS compilations passed; isolated same-source list/form/detail/CC/notices inspected, including live amount input and real scrolling. Repository quality gate: 441 PHPUnit passed, 2 skipped; 295 React tests passed; 64 OpenSpec validations and production build passed. Screenshots: oa-ui-review.html in the task visualization directory.
