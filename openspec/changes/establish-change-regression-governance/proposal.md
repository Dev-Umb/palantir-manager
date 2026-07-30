## Why

Palantir Manager currently has useful PHPUnit, Vitest, and online regression assets, but no shared contract distinguishes focused regression, commit validation, deployment evidence, and live verification. The existing online script also defaults to a live target and a development password while performing persistent writes.

## What Changes

- Define when material requirements need reviewed OpenSpec artifacts and when narrow maintenance may be exempt.
- Define layered regression evidence for PHPUnit Unit, PHPUnit Feature, React/Vitest, and explicit online verification.
- Add a risk-aware staged quality gate with a fail-closed default for unknown paths.
- Make the mutating online regression entry point disabled by default and require a fixed target, unique Run ID, process-only password, and explicit mutation authorization.

## Capabilities

### New Capabilities

- `change-governance`: Controls requirement scope, review, approval, validation, and evidence-backed task completion.
- `regression-evidence`: Defines proportional local and online regression layers and the meaning of their evidence.
- `risk-aware-quality-gate`: Selects staged checks by affected path while failing closed for unknown production or tooling paths.

### Modified Capabilities

- None.

## Impact

- Agent guidance gains Palantir-specific requirement, regression, and gate constraints.
- Composer and npm expose canonical focused, full, gate, OpenSpec, and online-regression entry points.
- The existing online regression script keeps its business flow but can no longer run with implicit target, authorization, Run ID, or password values.
- No application behavior, dependency manifest, schema, route, or permission changes are introduced.
