## 1. Requirement governance

- [x] 1.1 Add Palantir-specific scope, OpenSpec lifecycle, and evidence-separation rules to agent guidance.
- [x] 1.2 Add project OpenSpec configuration with proposal, design, spec, and task requirements.

## 2. Regression and gate entry points

- [x] 2.1 Add canonical focused, Unit, Feature, application, OpenSpec, gate, and online-regression Composer scripts.
- [x] 2.2 Add a risk-aware staged selector with a fail-closed unknown-path policy.
- [x] 2.3 Add a repository pre-commit hook and an explicit opt-in installer.
- [x] 2.4 Add unit coverage for backend, frontend, governance, docs-only, and unknown-path selection.

## 3. Online regression safety

- [x] 3.1 Remove implicit online target, Run ID, and password defaults.
- [x] 3.2 Require explicit enablement, mutation authorization, fixed origin, validated Run ID, and process-only password.
- [x] 3.3 Add automated coverage for allowed and rejected online configurations.

## 4. Verification

- [x] 4.1 Run focused Vitest coverage for the gate selector and online configuration.
- [x] 4.2 Run the PHPUnit application suite and production frontend build.
- [x] 4.3 Strictly validate all OpenSpec artifacts.
- [x] 4.4 Run the full quality gate and review the final diff for unrelated changes.
