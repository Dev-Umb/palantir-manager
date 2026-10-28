## 1. Review
- [x] 1.1 Obtain explicit approval for the 100 MiB limit.
- [x] 1.2 Verify provider file limits and peak memory before finalizing processing design.

## 2. Apply after approval
- [x] 2.1 Align request validation, UI limit and endpoint-specific transport settings.
- [x] 2.2 Provide clear oversize and non-JSON 413 errors with retry support.
- [x] 2.3 Test supported large/small files, boundary rejection, formats, authorization and unchanged adjacent endpoints.
- [x] 2.4 Run validation and quality gate; deploy scoped changes and verify upload and recognition separately.

## Verification evidence (2026-10-12)
- Final quality gate: PHPUnit 342 passed, 2 skipped; Vitest 258 passed; build and all 58 strict OpenSpec changes passed.
- Production endpoint accepted 60 MiB and 100 MiB plus multipart overhead; 102 MiB rejected. 35 interleaved contract/default/quotation requests confirmed scoped limits; explicit default PHP reset prevents worker setting reuse.
- Real TOS 60 MiB streaming write and SHA256 readback passed with 48 MiB peak memory. Exact 100 MiB valid synthetic PDF read, complete-page render and same-provider recognition passed (35 MiB peak; no business writes).
- Supplied source PDFs: all 26/27/13 pages rendered and online extraction returned complete fields plus evidence; successful recognition took 111.9/97/125 seconds respectively. Final derived image preview remains legible. Original PDFs were not altered; no contracts were archived by these checks.
- Confirmation PHPUnit: upload and preview do not write business records; explicit confirmation only updates selected project/contract; existing files, other projects and financial history retained; duplicate confirmation idempotent; wrong contract/project, forbidden field payload and stale preview rejected. React test proves preview and confirm click sequence and token-only confirmation.
- Production readback: no AI intake records match the three supplied filenames, and no intake was created today at check time. Thus there is no live user-confirmed import for these files; successful extraction tests must not be reported as production archival.
- Appended bytes, source digest, missing/non-contiguous pages, image budget, provider failure and temporary cleanup tests pass.
- Scoped application/config backups stored on server in storage/app/deploy-backups/contract-upload-20261012-100mb. Unrelated local changes were not deployed.
- Storage credentials cannot DeleteObject: one standalone synthetic test attachment remains staged, reduced to 608 bytes and labeled 非业务合同_上传回归测试.pdf. No intake/project/contract references it. Temporary copies of real source PDFs and test scripts are removed after checks.
