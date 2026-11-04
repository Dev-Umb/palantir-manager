## ADDED Requirements
### Requirement: Document-faithful November production overview
The system SHALL prioritize four follow-up queues and show estimated totals with category status rings without the standalone four-dimension comparison.
#### Scenario: Follow-up queues preserve distinct meanings
- **WHEN** a record is in production and partially shipped
- **THEN** it appears in both relevant queues and queue totals are not added together
#### Scenario: Undrawn unshipped records
- **WHEN** a non-deleted record is undrawn and unshipped
- **THEN** it appears in the new follow-up queue and remains counted once in unmade-unshipped estimated tonnage
#### Scenario: Empty and zero estimated weights
- **WHEN** category estimates are all missing or zero
- **THEN** missing values remain distinct from zero and the total does not show a misleading 100 percent
### Requirement: Excel-aligned production category lists
The system SHALL provide disjoint Excel group tabs and sortable suite quantities while preserving all original detail and export fields.
#### Scenario: Group selection
- **WHEN** a group tab is selected
- **THEN** progress, shipment and ring filters clear while search text remains
#### Scenario: Hydraulic quantity
- **WHEN** hydraulic records are listed
- **THEN** only hydraulic suite count is displayed, missing is shown as a dash, zero remains zero, and rod or pin counts stay in detail and export
#### Scenario: Retained persistence
- **WHEN** the updated interface opens
- **THEN** current database records, drafts, permissions, backup and material-demand functionality remain available without loading packaged demo data
