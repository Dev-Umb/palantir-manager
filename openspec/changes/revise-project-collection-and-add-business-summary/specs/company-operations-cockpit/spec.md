## MODIFIED Requirements

### Requirement: Dashboard shows total collection ratio

The company operations cockpit MUST show total collection ratio instead of weighted collection rate, using paid and occurred totals from the same authorized project-record set used by the project amount cards.

#### Scenario: Total collection ratio is available

- **WHEN** the authorized scope contains valid records with total occurred amount 1,000 and total paid amount 400
- **THEN** the cockpit MUST display total collection ratio as 40 percent
- **AND** MUST display or disclose the 400 numerator, 1,000 denominator, and record coverage

#### Scenario: Individual project ratios differ

- **WHEN** the authorized scope contains projects with different occurred amounts and collection percentages
- **THEN** the cockpit MUST divide summed paid amount by summed occurred amount
- **AND** MUST NOT calculate an arithmetic mean of project percentages

#### Scenario: Occurred total is unavailable

- **WHEN** no project in the authorized scope has a valid positive occurred amount
- **THEN** the cockpit MUST display `—` and explain that there is no calculable occurred-amount denominator
- **AND** MUST NOT fall back to contract amount or display 0 percent

#### Scenario: Total collection exceeds total occurred amount

- **WHEN** summed paid amount exceeds summed occurred amount in the covered records
- **THEN** the cockpit MUST display a ratio above 100 percent without capping it

#### Scenario: Project scope is restricted

- **WHEN** a non-administrator can view only a subset of projects
- **THEN** both ratio numerator and denominator MUST be calculated only from that same visible subset
- **AND** hidden project amounts MUST not be returned to the client
