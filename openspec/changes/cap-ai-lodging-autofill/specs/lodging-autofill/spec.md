## ADDED Requirements
### Requirement: Nightly cap applies only to AI lodging autofill
The system SHALL fill lodging expenses with the lesser of the valid recognized original amount and 100 CNY multiplied by confirmed nights, preserving original evidence and allowing manual override.

#### Scenario: Three nights above the cap
- **WHEN** AI recognizes a valid lodging amount of 450 CNY for three confirmed nights
- **THEN** the auto-filled amount is 300 CNY and the original amount and nights remain visible

#### Scenario: Below the cap
- **WHEN** the recognized lodging amount is 80 CNY for one night
- **THEN** the auto-filled amount remains 80 CNY

#### Scenario: Nights cannot be established
- **WHEN** lodging nights are unknown
- **THEN** the amount is not auto-filled and manual verification is requested without assuming one night

#### Scenario: Manual override and other expenses
- **WHEN** a user changes lodging amount to above the automatic cap or saves another expense type
- **THEN** normal save and submission validation applies without a nightly cap and existing records remain unchanged
