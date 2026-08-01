## 1. Requirement and metadata

- [x] 1.1 Add the project “知会人员” multi-account metadata and account option payload.
- [x] 1.2 Validate and normalize distinct business-role account IDs.

## 2. Visibility and authorization

- [x] 2.1 Extend project visibility for informed business users without extending linked-contract visibility.
- [x] 2.2 Add record-level update authorization so informed non-owners cannot update through UI or direct HTTP requests.
- [x] 2.3 Preserve owner, finance, administrator, and unrelated-business visibility behavior.

## 3. User interface

- [x] 3.1 Render the multi-select account control for project owners and administrators.
- [x] 3.2 Disable grid and modal editing for informed records.
- [x] 3.3 Show “知会项目” before the project name only for informed non-owner business users.

## 4. Verification

- [x] 4.1 Add PHPUnit Feature coverage for assignment, validation, visibility, direct-update denial, contract isolation, and preserved role behavior.
- [x] 4.2 Add Vitest coverage for the indicator and record-level read-only behavior.
- [x] 4.3 Run focused PHPUnit and Vitest checks.
- [x] 4.4 Run Pint, strict OpenSpec validation, production build, and the full quality gate.
