## ADDED Requirements

### Requirement: Preserve deployment attempts and the live version
The workbench SHALL persist each attempt and show the active version and deployer, timestamps and outcome when known.

#### Scenario: Publish a broken update
- **WHEN** a manager uploads an invalid archive to an existing site
- **THEN** the failed attempt records the failing stage and the stable public URL continues to open the previous working version

#### Scenario: Promote or roll back
- **WHEN** a manager selects an available successful version as current
- **THEN** the existing share URL opens that version, including its original relative assets, and the version history records the operation

#### Scenario: Preserve historical links and withdrawal
- **WHEN** existing deployments migrate or a manager withdraws a version
- **THEN** migration preserves prior URLs and withdrawal retains the history entry, tracks cache invalidation and disables preview and rollback for that withdrawn version

### Requirement: Operate deployments through the workbench
The management page SHALL support named sites, uploading ZIPs, viewing history, copying links, filtering by status and viewing failure details under existing management permissions.

#### Scenario: Navigate a site
- **WHEN** an authorized manager opens a site from the steel girder workbench
- **THEN** the current publication and upload action are visible ahead of its chronologically descending history, while anonymous visitors can only access published content
