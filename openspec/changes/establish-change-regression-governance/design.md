## Context

The repository uses PHPUnit rather than Pest, has no Browser suite, and uses SQLite in-memory for application tests. Its online regression script is a Node HTTP workflow that creates and updates records at `palantir.umb.ink`. Legato's principles are reusable, but its PostgreSQL profiles, Pest Browser groups, stateful Laravel commit gate, and CI delivery evaluator are not present here.

## Goals and non-goals

Goals:

- Make requirement scope and approval explicit before material implementation.
- Give each regression layer a distinct responsibility and evidentiary meaning.
- Provide a dependency-free local gate that is proportional for known paths and conservative for unknown paths.
- Prevent accidental online writes and credential persistence.

Non-goals:

- Introduce Pest, Playwright, PostgreSQL, coverage thresholds, or new dependencies.
- Claim CI, PR, deployment, or online verification where none exists.
- Redesign or clean up the business records created by the existing online regression flow.

## Decisions

### Requirement lifecycle

Material behavior and contract changes use `propose → review → apply`. Narrow restoration fixes and documentation or test maintenance may be exempt, but scope expansion triggers reassessment. Approval covers only the reviewed scope; archiving remains user-controlled.

Every material scope statement separates:

- `必须改变`
- `必须保持`
- `允许隐藏`
- `必须可见`
- `禁止推断`

This prevents a local requirement from silently becoming a global behavior or concealment policy.

### Regression layers

| Layer | Responsibility | Canonical entry | Evidence limit |
| --- | --- | --- | --- |
| L1 Unit | Pure rules and edge cases | `composer test:unit` | Does not prove HTTP or persistence |
| L2 Feature | HTTP, auth, persistence, transactions | `composer test:feature` | Does not prove browser rendering or deployment |
| L3 React | Rendering and interaction | `npm run test:ui` | Does not prove server authorization or layout geometry |
| L4 Online | Current deployed workflow | `composer test:online-regression` | Proves only the fixed target and executed Run ID |

Changes cover the target behavior, a preserved adjacent behavior, and the likely collateral boundary. Replacement work also maps old capabilities and fields to their new entry points before implementation.

### Risk-aware local quality gate

The pre-commit hook reads staged paths:

- backend paths select PHPUnit;
- frontend paths select Vitest and the production build;
- OpenSpec and governance paths select strict OpenSpec validation;
- documentation alone receives the staged diff check;
- unknown paths select all core checks.

`composer quality:gate` intentionally runs every check for delivery. The staged selector is an optimization, not a substitute for the full delivery gate.

### Online mutation safety

Online regression is separate from local and CI-default checks. Execution requires all of:

- `ONLINE_REGRESSION_ENABLED=1`
- `ONLINE_REGRESSION_ALLOW_MUTATIONS=1`
- exact `ONLINE_REGRESSION_BASE_URL=https://palantir.umb.ink`
- a validated unique `ONLINE_REGRESSION_RUN_ID`
- a non-default `ONLINE_REGRESSION_PASSWORD`

Credentials remain process-only. The current script leaves Run-ID-marked records as evidence because no verified cleanup contract exists; that limitation must remain visible until a dedicated cleanup capability is designed.

## Alternatives considered

- Port Legato's complete Laravel commit-gate subsystem: rejected because it adds substantial application code and maintenance before Palantir has equivalent test profiles or CI.
- Run every check on every commit: rejected for feedback cost; the full delivery gate still runs all checks.
- Allow configurable online origins: rejected because an environment typo could redirect a mutating suite to an unintended system.
- Put online regression in the default gate: rejected because local success, deployed behavior, and authorized online writes are distinct evidence.

## Rollback

The change is configuration and tooling only. Remove the hook path with `git config --unset core.hooksPath`, revert the new Composer/npm scripts and guard imports, and retain the OpenSpec artifacts as historical design evidence until the user explicitly archives or removes them.
