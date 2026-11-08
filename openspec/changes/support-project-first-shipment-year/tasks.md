## Review
- [x] Inspect current controls, validators, filters and mobile inputs; define scope and capability map.
- [x] Run strict OpenSpec validation (71 passed, 0 failed).
- [x] Obtain proposal approval before implementation (user: 实施，然后先不合并).

## Implementation and verification
- [x] Preserve a source snapshot on isolated codex/first-shipment-year branch; retain unrelated work. No merge or deployment authorized in this round.
- [x] Complete consumer inventory and update only the first shipment date contract.
- [x] Add focused L2 validation/permissions/date comparison/filter tests and L3 display/edit/inline-save tests.
- [x] Cover mobile input/filter retention, exports, invalid years, existing dates, empty values and unauthorized writes.
- [x] Run focused tests, applicable formatting, composer openspec:validate and composer quality:gate (477 PHPUnit passed, 3 skipped; 314 UI passed; 18 Flutter passed; 10 miniapp passed; production build passed).
- [ ] Merge PR before deployment; verify PC and mobile data preservation on deployed version.
- [ ] Back up and backfill nine years with per-field audit and concurrency checks; verify readback and visible year precision.
- [ ] Refresh unresolved source-row report; distinguish code, merge, deployment and data acceptance evidence.
