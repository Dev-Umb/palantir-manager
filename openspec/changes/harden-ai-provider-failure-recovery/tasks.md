## 1. Failure diagnosis and retry state

- [x] 1.1 Extract bounded sanitized HTTP diagnostics from provider request exceptions.
- [x] 1.2 Retry recoverable failures after partial output within the existing attempt budget.
- [x] 1.3 Clear backend projections and signal client output replacement on retry.

## 2. Regression coverage

- [x] 2.1 Add PHPUnit coverage for partial-output retry, empty-output retry, exhausted attempts, non-recoverable failures, and diagnostic redaction.
- [x] 2.2 Add Vitest coverage for replacing partial client state while preserving retry activity.

## 3. Verification and release

- [x] 3.1 Run focused backend/frontend tests, Pint, strict OpenSpec validation, and the full quality gate.
- [ ] 3.2 Merge the validated commit to `main` without modifying the user's dirty checkout.
- [ ] 3.3 Create production recovery points, deploy, restart workers, and run controlled online AI regression plus health checks.
