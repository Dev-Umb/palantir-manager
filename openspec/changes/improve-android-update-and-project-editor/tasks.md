## Review
- [x] Inspect client, attachment serialization, native bridge and existing release evidence.
- [x] Confirm optional startup/manual-check and system-install flow with user.
- [x] Review and approve proposal before implementation. User approved 2026-10-04.
- [x] Verify backend baseline: append-only; user approved minimal removal protocol and PC/APP sync. Update manifest proposed at same-origin /app-updates/android.json; publication remains separate.

## Apply
- [x] Implement update manifest checks and My Account entry without new dependencies.
- [x] Implement progress, cancellation, package validation and Android installation handoff.
- [x] Implement individual pending/saved attachment removal, undo and supported serialization in APP and PC Web; extend backend validation and locked transaction.
- [x] Unify editor cards, navigation, loading, retry and saving feedback.

## Verify
- [x] Run focused Flutter tests and backend/Web regressions for target behavior, preserved adjacent behavior and collateral boundaries: Flutter 84 passed; backend 16 passed; Web 5 passed. Native emulator checks: update 5 passed; session storage 7 passed.
- [x] Run Flutter static analysis and OpenSpec strict validation after material changes: no analysis issues; strict validation passed.
- [x] Run composer quality:gate: main checkout 133 passed/1 skipped plus Web 147; matching backend checkout 179 passed/1 skipped plus Web 215; production bundles passed.
- [x] Build with existing release signing and exercise Android 14 / 1080×2340 / 420 dpi emulator: release APK installed and launched; debug APK verified against isolated local backend; saved-file removal 3→2, undo and required-evidence rejection passed. Update download/install from a published manifest remains unverified.
- [x] Record code, APK build, manifest publication and actual update installation separately in outputs/app-maintenance-20261004/validation.json; no publication/deployment/installation claimed and no archive performed.

- [x] Repair an existing Inertia bootstrap compatibility failure found on the emulator: recover version from same-origin v3 initial HTML when a 409 omits the version header; preserve cookie isolation and bounded retry. API regression 23 passed, including 3 new cases.
