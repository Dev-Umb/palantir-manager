## ADDED Requirements
### Requirement: Customer name substring filters
Customer relation filters SHALL support contains and not_contains using current customer names and literal case-insensitive substrings, without changing other relation fields or authorization.

#### Scenario: Contains
- **WHEN** a customer name contains the supplied keyword
- **THEN** contains includes its visible related records and not_contains excludes them

#### Scenario: Negative and missing customers
- **WHEN** a record has a resolved customer whose name does not contain the keyword
- **THEN** not_contains includes it, while missing or unresolved customer relations match neither fuzzy operator

#### Scenario: Preserved exact and empty filters
- **WHEN** an existing exact-ID or empty filter is selected
- **THEN** its prior behavior remains unchanged and exact matching uses the customer selector

#### Scenario: Operator input switch
- **WHEN** switching between exact and fuzzy operators
- **THEN** the UI resets the old value and provides a selector or text input as appropriate

#### Scenario: Scoped query composition
- **WHEN** fuzzy filters are combined using AND or OR
- **THEN** existing visibility boundaries, pagination and export filtering remain enforced
