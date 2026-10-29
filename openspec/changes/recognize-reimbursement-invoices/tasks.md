- [x] Implement deterministic extraction and bounded OCR fallback with private access control.
- [x] Add mobile recognition status, retry and explicit amount confirmation.
- [x] Test field extraction, conflicting totals, authorization, failures and mobile state boundaries.
- [x] Validate the user-supplied sample privately; keep real invoice data out of repository fixtures.
- [x] Run focused checks and quality gate.
- [ ] Release after merged PR and server tool checks; complete real-device acceptance.

Private sample: real PDF pipeline verified tax-inclusive total 11300 cents with Poppler; rasterized page plus local Chinese OCR also returned 11300 cents, with company-name OCR errors requiring manual review. No real invoice fixture is committed.
