## ADDED Requirements

### Requirement: Preserve year precision in first shipment dates
The system SHALL accept a four-digit year or a valid full ISO date only for the project's first_shipment_date and SHALL preserve the supplied precision through persistence, display, editing and export.

#### Scenario: Save a year without inventing a day
- **WHEN** an authorized user saves first_shipment_date as 2016
- **THEN** the persisted value is 2016 and the visible value identifies the month and day as unknown
- **AND** no January 1 date is generated

#### Scenario: Preserve adjacent date contracts
- **WHEN** a user submits a full first shipment date, clears it, or submits a year to another date field
- **THEN** full-date and clearing behavior remain supported and the other date field rejects the year
- **AND** role permissions and payment reminder rules remain unchanged

### Requirement: Compare uncertain dates without fabricating precision
The system SHALL use the possible year interval only for comparisons and SHALL preserve existing full-date ordering checks.

#### Scenario: Validate a same-year last shipment
- **WHEN** first shipment is 2016 and last shipment is 2016-06-01
- **THEN** the date pair is accepted without assigning a specific first shipment day

#### Scenario: Reject a definitely earlier last shipment
- **WHEN** first shipment is 2016 and last shipment is 2015-12-31
- **THEN** the change is rejected atomically with a clear field error

#### Scenario: Filter by year and exact day
- **WHEN** a user filters first shipment by year 2016
- **THEN** both year-only 2016 and full dates in 2016 match
- **AND** an exact-day filter never matches a year-only date

### Requirement: Safely backfill the approved statement years
The system SHALL backfill only the nine confirmed year-only source rows after compatible code is merged, deployed and verified, retaining backup, audit and concurrency protection.

#### Scenario: Avoid reducing known precision
- **WHEN** a target project already has a full first shipment date at write time
- **THEN** that row is not overwritten with a year and is reported for review
