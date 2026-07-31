## Context

The production database is PostgreSQL, where invalid bigint and UUID literals can fail before Eloquent returns an ordinary missing-model result. Local tests use SQLite and therefore must assert validation and binding contracts directly. Internal and public team-log routes share one React page. The deployment script currently reloads nginx but does not manage its site configuration.

## Goals / Non-Goals

**Goals:**

- Convert malformed public input into stable 4xx behavior with Chinese user-facing feedback.
- Preserve numeric business-object IDs while adding key-based binding compatibility.
- Keep internal and public team-log form behavior aligned.
- Provide auditable asset-delivery configuration without silently rewriting server infrastructure.

**Non-Goals:**

- Change object routes, authorization, archive-table policy, relation semantics, or upload validation.
- Clean production data, alter production credentials, or deploy automatically.
- Assume brotli is installed or modify `/etc/nginx` from the application deployment script.

## Decisions

### Binding and relation validation

`BusinessObject::resolveRouteBinding()` will select `id` only for an all-digit value and `key` otherwise. `ObjectRecord::resolveRouteBinding()` will reject non-UUID values before querying. Relation validators and the requisition request rule will apply Laravel's UUID rule before any record query and use a shared Chinese message.

Alternative considered: convert every frontend route to key binding. Rejected because numeric IDs are an explicitly preserved public calling contract.

### Not-found rendering

Central exception rendering will return a generic message when debug mode is disabled. JSON-expecting requests receive JSON; Inertia and browser requests retain an ordinary 404 response without model class details. Debug mode keeps framework detail for local diagnosis.

Alternative considered: catch missing models in each controller. Rejected because it duplicates policy and misses implicit bindings.

### Team-log form behavior

A small local-date helper will use local date fields rather than UTC serialization. A reusable controlled file input component will keep the real input accessible through a label, show the selected filename, and clear both DOM and form state.

Alternative considered: styling the browser-native `::file-selector-button`. Rejected because the adjacent native "no file chosen" text remains browser-language dependent.

### Asset delivery

The repository will contain an nginx snippet for gzip and immutable caching under `/build/assets/`. The deployment script will only inspect the active site configuration and print a deterministic installation instruction when the snippet is absent.

Alternative considered: have `deploy.sh` write `/etc/nginx`. Rejected because it would mutate infrastructure without a reviewed host-specific configuration.

## Risks / Trade-offs

- [A key containing only digits would bind as an ID] → Business-object keys are already non-numeric; preserve numeric ID compatibility and cover both paths.
- [Central 404 rendering could alter unrelated errors] → Limit customization to `ModelNotFoundException` and test that 403 and 422 remain unchanged.
- [A hidden file input can become inaccessible] → Use a semantic label, focusable control path, filename status, and explicit clear button.
- [nginx include paths vary by host] → Keep the script non-mutating and require backup plus `nginx -t` before manual activation.

## Migration Plan

1. Deploy application code and the versioned nginx snippet.
2. Back up the active nginx site configuration.
3. Include the snippet manually in the confirmed site configuration.
4. Run `nginx -t`, reload nginx, and verify gzip plus immutable caching with direct headers.
5. Roll back application behavior with the prior release; remove the include and reload nginx to roll back asset policy.

## Open Questions

- The active production nginx site filename and optional brotli availability remain deployment-time observations and are not inferred here.
