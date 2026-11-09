## ADDED Requirements
### Requirement: Fixed daily allowance applies only to AI lodging autofill
The system SHALL fill lodging expenses with 100 CNY multiplied by confirmed nights for valid recognized lodging evidence, regardless of whether its original amount is lower or higher, preserving original evidence and allowing manual override.

#### Scenario: Three nights
- **WHEN** AI recognizes a valid lodging amount of 450 CNY for three confirmed nights
- **THEN** the auto-filled amount is 300 CNY and the original amount and nights remain visible

#### Scenario: Original amount below the allowance
- **WHEN** the recognized lodging amount is 80 CNY for one night
- **THEN** the auto-filled amount is 100 CNY and the original amount remains 80 CNY

#### Scenario: Nights cannot be established
- **WHEN** lodging nights are unknown and the single-room fallback does not apply
- **THEN** the amount is not auto-filled and manual verification is requested without assuming one night

#### Scenario: Manual override and other expenses
- **WHEN** a user changes lodging amount to above or below the automatic allowance or saves another expense type
- **THEN** normal save and submission validation applies without a nightly cap and existing records remain unchanged

#### Scenario: One room without stay dates
- **WHEN** a lodging detail explicitly has quantity one room and has no explicit nights or stay dates
- **THEN** recognition treats it as one day under the approved business convention, records that basis, and does not flag missing nights alone as an error
- **AND** explicit stay nights or dates take precedence; missing quantity or multiple rooms must not default to one day
