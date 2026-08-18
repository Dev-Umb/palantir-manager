## 1. Proposal and review

- [x] 1.1 Confirm strict formula, zero/missing denominator behavior, uncapped over-collection, project-inherited visibility, and existing-project recalculation with the user.
- [x] 1.2 Define the scope contract, virtual projection architecture, migration safety, rollback, and evidence boundaries.
- [x] 1.3 Run `composer openspec:validate` and resolve every strict validation error.
- [x] 1.4 Obtain explicit user approval for this proposal before implementation.

## 2. Project collection formula and existing-data operation (after approval)

- [x] 2.1 Centralize the collection-progress calculation so project saves, dashboard aggregation, and recalculation cannot drift.
- [x] 2.2 Change project collection progress to use only valid positive occurred amount, return unavailable for invalid denominators, and preserve results above 100 percent.
- [x] 2.3 Add a preview-first, explicit-execute, batched and idempotent Artisan command for existing project mirrors; do not execute it against production in this change.
- [x] 2.4 L1/L2 PHPUnit: normal, zero, missing, negative, invalid, non-finite and over-100 percent inputs; no contract fallback; unchanged adjacent unpaid/invoice/status behavior.
- [x] 2.5 L2 PHPUnit: recalculation dry-run has no writes; execute changes only drifted projects; repeat is idempotent; every adjacent project field remains unchanged; no deprecated finance ledger dependency is introduced.

## 3. Total collection ratio (after approval)

- [x] 3.1 Replace the cockpit's weighted collection rate contract and implementation with total paid divided by total occurred for one identical authorized record set.
- [x] 3.2 Return numerator, denominator, ratio, covered-record count and total-record count; return unavailable for zero/absent denominator and allow totals above 100 percent.
- [x] 3.3 L2 PHPUnit: admin full scope, salesperson own scope, cross-salesperson isolation, missing/invalid data, zero denominator, unequal project sizes and over-100 percent total.
- [x] 3.4 L3 Vitest: “总回款比例” replaces “加权回款率”; ratio, `—`, numerator/denominator, coverage and over-100 percent rendering are accessible.

## 4. Read-only business summary projection (after approval)

- [x] 4.1 Add a main-data-level “业务概括表” metadata/navigation entry backed directly by project records and dependent on `object.project.view`.
- [x] 4.2 Implement scoped projection pagination, search, sort, selected-row and export behavior for the seven approved columns, reusing `ProjectVisibility` and relation-label loading.
- [x] 4.3 Keep every summary control read-only; reject direct create/update/delete requests and link edits to the existing project record route with target-route authorization.
- [x] 4.4 L2 PHPUnit: exact columns and mapping, no summary records, salesperson A/B isolation across all read surfaces, admin scope, no-permission omission, write rejection, and project-link authorization.
- [x] 4.5 L3 Vitest: same-level entry, read-only message, seven columns, amount/null formatting, project link and absence of create/edit/delete/bulk-write controls.
- [x] 4.6 Hide the standalone customer-contact navigation, tab, list and export while preserving customer/project embedded contact maintenance; cover both concealment and preserved writes in L2/L3 regressions.

## 5. Validation and delivery (after approval)

- [x] 5.1 Run focused backend tests with `composer test:narrow -- <test-file-or-filter>` and focused frontend tests with `npm run test:ui -- <test-file>`.
- [x] 5.2 Run `vendor/bin/pint --dirty --format agent` for PHP changes, then rerun every directly affected test.
- [x] 5.3 Run `npm run build`, `composer openspec:validate`, and `composer quality:gate`.
- [x] 5.4 Review the final diff against required changes, preserved behavior, permitted concealment, required visibility and prohibited inference.
- [x] 5.5 Report code, tests, commit/PR, deployment, production recalculation and online verification as separate evidence; do not run production recalculation, deploy, or archive without separate authorization.
