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
- **WHEN** lodging nights cannot be established because of conflicting information or multiple rooms, and neither the single-room nor missing-information fallback applies
- **THEN** the amount is not auto-filled and manual verification is requested without assuming one night

#### Scenario: Manual override and other expenses
- **WHEN** a user changes lodging amount to above or below the automatic allowance or saves another expense type
- **THEN** normal save and submission validation applies without a nightly cap and existing records remain unchanged

#### Scenario: One room without stay dates
- **WHEN** a lodging detail explicitly has quantity one room and has no explicit nights or stay dates
- **THEN** recognition treats it as one day under the approved business convention, records that basis, and does not flag missing nights alone as an error
- **AND** explicit stay nights or dates take precedence; missing quantity with no nights or stay dates defaults to one day; explicit multiple rooms or conflicting information still require verification

#### Scenario: No quantity or stay duration
- **WHEN** a valid lodging invoice has no quantity, stay duration, or check-in/check-out dates
- **THEN** recognition uses one day under the approved business convention and auto-fills 100 CNY, preserving the original amount and stating the default basis
- **AND** missing duration alone does not produce an error; users can manually change the amount
