## 1. Requirement and evidence

- [x] 1.1 Confirm that the requested order applies to project rows rather than field columns.
- [x] 1.2 Confirm a hidden payload key, default/filter behavior, explicit-sort override, and unranked fallback.
- [x] 1.3 Run `composer openspec:validate`.

## 2. Implementation

- [x] 2.1 Apply project-only default numeric ordering with nulls last and stable fallback keys.
- [x] 2.2 Keep frontend fields, controls, and copy unchanged; do not expose the hidden key.
- [x] 2.3 Write statement order only for uniquely matched projects, with backup and drift checks.
- [x] 2.4 Preserve an existing hidden statement order during ordinary project edits.

## 3. Regression evidence

- [x] 3.1 L2: default project list follows numeric statement order and unranked records follow ranked records.
- [x] 3.2 L2: filtered/searched default results preserve statement order; explicit sort still overrides it; non-project defaults remain unchanged.
- [x] 3.3 Frontend is unchanged and the hidden key is absent from object field metadata.
- [x] 3.4 L2: ordinary project edits preserve the existing hidden statement order.
- [ ] 3.5 Run focused tests, Pint, build, strict OpenSpec validation, and the applicable quality gate.
