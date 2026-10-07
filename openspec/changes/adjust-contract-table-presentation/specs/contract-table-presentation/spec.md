## ADDED Requirements

### Requirement: Contract table display
The contract table SHALL display amount and subtotal in ten-thousand yuan, hide contract number columns, and show a first view-only sequence column. Persistent values and other views SHALL remain unchanged.

#### Scenario: Paginated results
- **WHEN** page two contains 50 results per page
- **THEN** sequence starts at 51 and amount 10000 displays as 1.00

#### Scenario: Empty and historical values
- **WHEN** amount is null or zero
- **THEN** null remains empty and zero displays as 0.00 without changing stored values
