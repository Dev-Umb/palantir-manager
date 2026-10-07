## ADDED Requirements

### Requirement: Independent visualization page preserves the original dashboard

The system MUST provide a separate read-only visualization page and an authorized navigation entry while preserving the original dashboard, tables, workflows and routes. The new page MUST primarily render charts, metric cards and readable legends without directly rendering detail tables.

#### Scenario: User navigates between views

- **WHEN** an authorized user opens the visualization page
- **THEN** the user MUST be able to return to the original dashboard and access authorized existing details
- **AND** the original dashboard MUST retain its existing capabilities and default landing behavior

### Requirement: Company collection chart uses the existing metric source

The company collection doughnut MUST use the same authorized numerator and denominator as the existing total collection ratio and MUST disclose amounts, units, coverage and scope.

#### Scenario: Normal or zero collection

- **WHEN** occurred is 1000 and paid is 400, zero or 1000 in the metric's existing covered set
- **THEN** the chart MUST show 40, 0 or 100 percent respectively with paid and remaining labels

#### Scenario: Missing or abnormal collection

- **WHEN** the denominator is unavailable or not positive, required amounts are missing, paid exceeds occurred or an amount is negative
- **THEN** the panel MUST show the relevant empty or abnormal explanation without inventing zero amounts or drawing misleading sectors
- **AND** an available actual ratio above 100 percent MUST remain visible without clamping

### Requirement: Salesperson distributions have separate denominators

The page MUST show three distinct salesperson doughnut charts for occurred, unpaid and paid amounts using the verified existing project-master businessperson projection. Negative source amounts MUST remain in actual salesperson net totals with disclosure; nonnegative net totals MAY be charted, while negative sector totals MUST NOT be drawn. Each chart MUST divide each salesperson's amount by the visible valid total of that same metric, without mixing finance metric sources or overlapping amount categories as sectors.

#### Scenario: Three independent distributions

- **WHEN** salesperson A has occurred 60, unpaid 10, paid 50 and salesperson B has occurred 40, unpaid 30, paid 10
- **THEN** A's shares MUST be 60 percent occurred, 25 percent unpaid and approximately 83.33 percent paid in three separate charts
- **AND** every chart MUST disclose its own total and coverage

#### Scenario: Unassigned or multiply assigned projects

- **WHEN** a visible project has no salesperson or multiple salespeople without an existing unique allocation rule
- **THEN** the project MUST be shown in an explicit unmaintained or unallocated category
- **AND** the amount MUST NOT be duplicated or arbitrarily allocated to named salespeople

#### Scenario: Zero, missing or abnormal amounts

- **WHEN** valid totals are zero or data contains missing, negative or non-finite values
- **THEN** the page MUST distinguish zero from missing and disclose abnormal coverage
- **AND** MUST NOT render invalid sectors or silently omit anomalies

### Requirement: Stage distribution follows the existing active-project definition

The page MUST show current stages of visible projects with one of the existing active overall statuses: tendering, awarded, processing-letter received or contract signed. Unmaintained stages MUST have an explicit sector; paused, completed, terminated and unmaintained overall states MUST appear as separate non-active summaries.

#### Scenario: Mixed project states

- **WHEN** visible projects have active, inactive and unknown stages or statuses
- **THEN** sector counts, percentage denominator and center active-project total MUST agree
- **AND** inactive states MUST NOT enter the active-stage doughnut

### Requirement: Authorization and accessible chart labels preserve data boundaries

The new page MUST require existing dashboard permission and MUST filter each source by existing object permissions and ProjectVisibility before aggregation. Chart labels, values, units, scope, time and coverage MUST be available without relying exclusively on color or hover.

#### Scenario: Restricted or absent source permission

- **WHEN** a user has restricted project scope or lacks permission to view a chart's source
- **THEN** hidden source amounts MUST NOT appear in the server response
- **AND** unavailable panels MUST be omitted while authorized panels remain usable

#### Scenario: Read-only and responsive behavior

- **WHEN** a user opens the page on desktop or mobile
- **THEN** the page MUST NOT mutate business records or timestamps
- **AND** charts and original-page links MUST remain readable and usable with equivalent text labels

### Requirement: Approved project charts replace salesperson comparisons

The page MUST replace the salesperson amount comparison with the top five visible projects ordered by unpaid amount descending, each showing occurred, paid and unpaid amounts separately. It MUST replace the unpaid salesperson ranking with a project elapsed-without-payment table, ordered longest first with increasing visual emphasis.

#### Scenario: More than five unpaid projects

- **WHEN** six or more visible projects have valid positive unpaid amounts
- **THEN** the comparison MUST default to the five largest unpaid amounts with deterministic tie ordering
- **AND** project labels and all three available amounts MUST be readable

#### Scenario: Elapsed time cannot be calculated

- **WHEN** a required source date is absent, invalid or in the future
- **THEN** the time table MUST disclose that duration is unavailable without inventing a date

### Requirement: Payment aging uses maintained payment dates only

The payment aging ranking MUST include only visible projects with valid positive unpaid amounts and a maintained valid last_payment_date not in the future. Elapsed days MUST be calendar days from that date to today; missing dates MUST NOT fall back to project creation dates.

#### Scenario: Missing last payment date

- **WHEN** an unpaid project has no valid maintained last payment date
- **THEN** it MUST NOT enter the aging ranking
- **AND** it MUST remain eligible for the top-five unpaid amount chart
- **AND** the excluded count MUST be disclosed

### Requirement: Chart sectors expose values dynamically

All visualization doughnuts MUST show the hovered or keyboard-focused sector's category, original amount or project count, and percentage in a tooltip. The active sector MUST receive a subtle animated highlight, with motion disabled when the user prefers reduced motion.

#### Scenario: Pointer enters and leaves a sector

- **WHEN** the pointer enters a chart sector
- **THEN** the corresponding values MUST appear without a click and the sector MUST be highlighted
- **WHEN** the pointer leaves that sector
- **THEN** the tooltip and highlight MUST clear
- **AND** persistent numeric legends MUST remain available
