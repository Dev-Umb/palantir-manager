## ADDED Requirements
### Requirement: Supplied report presentation
The procurement overview SHALL follow the supplied HTML report presentation while retaining platform controls.
#### Scenario: Overview and detail navigation
- **WHEN** an authorized user opens the overview and clicks a project title
- **THEN** a teal link targets that record's inline full-detail article, with quantity, materials, contacts, judgment, terms, raw dates and source link
#### Scenario: Pending data and mobile display
- **WHEN** pending records or a narrow screen are present
- **THEN** pending articles remain visible separately, report tables scroll within their container and article date strips stack
#### Scenario: Preserved boundaries
- **WHEN** the report renders user-supplied text and source links
- **THEN** text remains inert, unsafe links are not clickable, uncertainty and invited restrictions remain visible and platform filters and AI entry remain available
### Requirement: Priority order before pagination
The scoped query SHALL order matching records by P1, P2, P3 and pending, then newest upload batch and original record insertion order before pagination.
#### Scenario: Filtered paginated results
- **WHEN** matching records span priorities and pages
- **THEN** high priority matching records appear first while counts, time and method filters and invited-record permissions remain unchanged
