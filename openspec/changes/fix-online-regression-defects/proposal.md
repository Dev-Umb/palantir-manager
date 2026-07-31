## Why

Production regression found malformed route and relation identifiers returning 500 responses, UTC-derived team-log dates, an English native file control, internal model names in 404 responses, and fingerprinted assets without compression or immutable caching guidance. These defects make valid workflows fragile and expose implementation details without changing the intended business rules.

## What Changes

- Resolve business objects by either the existing numeric ID contract or their key, while rejecting missing objects with 404.
- Reject malformed UUID route and relation identifiers before PostgreSQL casts them, returning 404 for route binding and Chinese 422 validation feedback for submitted relations.
- Render production model-not-found responses without internal model class names while preserving existing 403 and 422 behavior.
- Derive the team-log default date from the browser's local calendar date.
- Replace the team-log native file picker presentation with an accessible Chinese picker, selected filename, and clear action while preserving accepted types and upload behavior.
- Add a versioned nginx asset-delivery template and a non-mutating deployment check that explains how to install it when missing.

## Capabilities

### New Capabilities

- `resilient-object-inputs`: Covers compatible object route binding, malformed UUID handling, safe not-found responses, and preserved authorization and validation boundaries.
- `localized-team-log-form`: Covers local-calendar defaults and localized attachment selection for internal and public team-log forms.
- `versioned-asset-delivery`: Covers gzip and immutable cache policy for fingerprinted Vite assets without automatic infrastructure mutation.

### Modified Capabilities

- None.

## Impact

- Affects `BusinessObject` and `ObjectRecord` route binding, relation validation, requisition validation, centralized exception rendering, the shared team-log React page, deployment tooling, and nginx configuration guidance.
- Adds PHPUnit Feature and Vitest regression coverage.
- Does not change routes, permissions, archived-table policy, relation targets, accepted upload types, business derivation, online data, dependencies, or deployed nginx automatically.
