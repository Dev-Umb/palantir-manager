## ADDED Requirements

### Requirement: Negotiate compressed shared JSON responses

The Palantir server MUST compress eligible existing application/json responses when the client accepts gzip, preserving the decoded response and all existing business authorization and HTTP semantics. Clients that do not accept gzip MUST retain identity responses.

#### Scenario: Supported client reads business data

- **WHEN** an authenticated desktop or Android client requests an existing eligible JSON page with gzip accepted
- **THEN** the response MUST use gzip with Vary containing Accept-Encoding and decode to the existing authorized data without dropping fields or introducing stale cached records

#### Scenario: Identity client and excluded data

- **WHEN** a client requests identity or forbids gzip, or the response is below the existing compression threshold
- **THEN** the server MUST preserve a readable identity response and existing status codes

#### Scenario: Preserve adjacent static behavior

- **WHEN** a client loads existing CSS or JavaScript assets or requests a missing static asset
- **THEN** existing compression, cache headers and missing-resource status MUST remain unchanged

#### Scenario: Deployment validation fails

- **WHEN** configuration validation or authenticated response verification fails after preparing the targeted change
- **THEN** the previous Palantir configuration MUST remain active or be restored without changing other applications
