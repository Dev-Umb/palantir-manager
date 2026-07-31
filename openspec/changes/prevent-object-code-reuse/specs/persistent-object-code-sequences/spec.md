## ADDED Requirements

### Requirement: Issued object codes are not reused after deletion

The application MUST persist the last issued number independently of live object records and MUST NOT issue a previously allocated code after its record is deleted.

#### Scenario: Latest record is deleted

- **WHEN** the highest numbered record for a prefix and date is deleted and another record is created
- **THEN** the new record receives the following sequence number rather than the deleted code

#### Scenario: Historical records predate sequence state

- **WHEN** a prefix and date has matching records but no sequence row
- **THEN** the first allocation initializes above the highest existing numeric suffix

### Requirement: Code allocation is atomic per prefix and date

The application MUST serialize code allocation for the same prefix and date and MUST retain the existing daily code format.

#### Scenario: Two creations contend

- **WHEN** two transactions allocate a code for the same prefix and date
- **THEN** they receive distinct monotonically increasing numbers

#### Scenario: A new local date begins

- **WHEN** the first record for a prefix is created on a new date
- **THEN** its code uses that date and begins from the highest existing number for that date plus one

#### Scenario: Adjacent object creation behavior

- **WHEN** a valid object record is created
- **THEN** payload validation, derived records, authorization, title generation, and the `{PREFIX}-{YYYYMMDD}-{NNN}` shape remain unchanged
