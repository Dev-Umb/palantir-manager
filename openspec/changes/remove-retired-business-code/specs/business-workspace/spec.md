## ADDED Requirements
### Requirement: Retired object code removal
The system SHALL keep only retained business objects in active object configuration and remove retired purchase request and team log routes and implementations.

#### Scenario: Retained business remains accessible
- **WHEN** a user accesses an authorized retained business table
- **THEN** its existing fields and permissions remain available

#### Scenario: Retired runtime entry points
- **WHEN** a user accesses a retired purchase request or team log entry point
- **THEN** the route is absent

### Requirement: Historical data retention
Metadata synchronization SHALL preserve stored records of retired objects without recreating retired runtime definitions.

#### Scenario: Metadata update after retirement
- **WHEN** metadata synchronization runs with retired definitions removed
- **THEN** historical records remain stored and no retired reference data is seeded

### Requirement: Retired integrations cannot influence current workspaces
The system SHALL exclude retired object fields and queries from AI and MCP tools and the operating dashboard while retaining current customer tools and project financial summaries.

#### Scenario: Archived retired data exists
- **WHEN** an administrator queries current tools or opens the dashboard
- **THEN** retired records remain stored but do not reappear as active objects or production panels
