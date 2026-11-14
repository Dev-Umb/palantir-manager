## Tasks
- [x] OpenSpec strict validation
- [x] Implement direct customer entry and preserved existing selection
- [x] Test atomic request, conflict confirmation, permission and preserved edit behavior
- [x] Full quality gate and mini program compilation
- [ ] Merge PR before uploading merged revision; report upload separately from formal release

Evidence: 120 mini program tests passed; ProjectCustomerInlineSyncTest 6 passed / 53 assertions; quality:gate passed with 583 backend tests, 5 skipped, 387 UI tests, production build and strict OpenSpec validation. Edit page WXML compiled successfully. No production business records created.
