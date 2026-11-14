## Tasks
- [x] OpenSpec strict validation
- [x] Implement direct customer entry and preserved existing selection
- [x] Test atomic request, conflict confirmation, permission and preserved edit behavior
- [x] Full quality gate and mini program compilation
- [ ] Merge PR before uploading merged revision; report upload separately from formal release

Evidence: 120 mini program tests passed; ProjectCustomerInlineSyncTest 6 passed / 53 assertions; quality:gate passed with 583 backend tests, 5 skipped, 387 UI tests, production build and strict OpenSpec validation. Edit page WXML compiled successfully. No production business records created.

## User correction
- [x] Restore main project form and relocate profile fields to secondary page
- [x] Verify draft handoff, cancellation, session boundary, old selection and standalone creation
- [x] Run quality gate and compile both templates
- [ ] Merge before uploading corrected version

Correction evidence: 128 mini program tests passed, complete quality gate passed, both WXML templates and customer WXSS compiled. An isolated simulator fixture with production network disabled verified search prefill, secondary form, more-fields expansion, and returning customer/contact while retaining project name and remark. Screenshots are local review evidence, not real-account acceptance.
