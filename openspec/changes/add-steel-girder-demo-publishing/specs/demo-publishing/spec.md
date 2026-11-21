## ADDED Requirements

### Requirement: Publish an offline presentation
The workbench SHALL accept a static ZIP from an authenticated steel girder manager and expose a unique anonymous HTTPS URL only after successful publication.

#### Scenario: Play a complete presentation
- **WHEN** a manager publishes an index.html and nested script, image and video assets
- **THEN** a guest opening the copied URL can run the animation and seek through the video using the original relative links

#### Scenario: Publish a single named HTML file
- **WHEN** the ZIP contains 吊车吊装演示.html and 打开说明.txt without an index.html
- **THEN** the share URL opens the uniquely named HTML entry with its relative asset paths preserved

#### Scenario: Reject invalid packages
- **WHEN** the archive lacks an entry point, exceeds extraction limits or contains unsafe paths
- **THEN** publication fails with a reason and no share link

#### Scenario: Keep management private
- **WHEN** a guest or a view-only user requests a publishing operation
- **THEN** access is denied and existing project and drawing permissions still apply

### Requirement: Withdraw a presentation
The workbench SHALL let a manager withdraw a deployment and report completion after origin removal and CDN invalidation.

#### Scenario: Withdraw one deployment
- **WHEN** withdrawal completes
- **THEN** new requests for the entry and its assets fail while other deployments remain available
