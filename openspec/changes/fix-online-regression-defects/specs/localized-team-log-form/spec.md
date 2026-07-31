## ADDED Requirements

### Requirement: Team-log forms default to the local calendar date

The internal and public team-log forms MUST initialize the work date from the user's local calendar date rather than the UTC date.

#### Scenario: Local day differs from UTC day

- **WHEN** the form opens at `2026-07-31T02:00:00+08:00`
- **THEN** the default work date is `2026-07-31`

#### Scenario: User edits the default date

- **WHEN** the user selects another valid date
- **THEN** the existing submitted `work_date` behavior remains unchanged

### Requirement: Team-log attachment selection is localized

The team-log form MUST present Chinese attachment actions, show the selected filename, and allow clearing while retaining the current accepted file types and submission behavior.

#### Scenario: User selects an attachment

- **WHEN** the user activates `选择照片` and selects a permitted file
- **THEN** the filename is shown and the selected `File` remains in form state

#### Scenario: User clears an attachment

- **WHEN** the user activates `清除`
- **THEN** the filename returns to the Chinese empty state and form state contains no attachment
