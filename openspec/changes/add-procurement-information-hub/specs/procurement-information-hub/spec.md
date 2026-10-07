## ADDED Requirements

### Requirement: Independent business boundary
The system SHALL serve the hub using hub tables and shared identity, with only an authorized read-only projection of existing project master rows; it SHALL NOT query other ERP business objects or mutate any ERP data.
#### Scenario: ERP is empty
- **WHEN** an authorized user opens or analyzes hub data without ERP records
- **THEN** hub functions remain available and public opportunities remain usable without company configuration
#### Scenario: Access control
- **WHEN** a non-admin requests source or company management
- **THEN** access is denied while authorized readers retain announcements and private follows

### Requirement: Traceable collection
The system SHALL retain source URLs, timestamps, immutable evidence versions and missing fields; collection SHALL require enabled sources and safe public destinations.
#### Scenario: Reprint or correction
- **WHEN** a duplicate or changed announcement is collected
- **THEN** duplicate observations are not counted twice and changes retain previous evidence and invalidate affected reports
#### Scenario: Source failure
- **WHEN** a source fails or contains untrusted instructions
- **THEN** existing data remains available and instructions cannot expand agent permissions

### Requirement: Bounded four-agent research
The system SHALL persist collection, analysis, recommendation and orchestration/audit work with independent contexts and at most two corrective rounds.
#### Scenario: Unsupported recommendation
- **WHEN** references are invalid or the auditor requests correction
- **THEN** the report is withheld, bounded correction is scheduled, and unresolved work is marked insufficient
#### Scenario: Cancellation and retry
- **WHEN** a task is cancelled, duplicated or fails transiently
- **THEN** no cancelled or duplicate result is published and failures remain observable

### Requirement: Comparable prices and project assistance
The system SHALL calculate statistics only within comparable units, scope and price types, and SHALL use only the current viewer’s authorized project master projection for internal assistance, without treating project existence as a bid or win.
#### Scenario: Missing comparability
- **WHEN** price basis or project evidence is missing
- **THEN** limitations are shown without fabricated prices, winning probabilities or cooperation claims
#### Scenario: Import preview
- **WHEN** an administrator uploads historical data
- **THEN** validation and preview precede confirmation and the import never writes ERP records

### Requirement: Information pages and follows
The system SHALL provide announcement search/details, research, company management, source/task administration and user-owned bookmarks/subscriptions.
#### Scenario: Upstream change
- **WHEN** a followed announcement or authorized project projection changes
- **THEN** hub updates and stale-report indicators appear without creating ERP notifications

### Requirement: Automatic recommendation-first experience
The system SHALL show explained opportunity screening by default, initialize verified sources automatically, and queue deduplicated background analysis without asking users to configure sources or company profiles. Initial screening SHALL be distinguished from audited AI recommendations.
#### Scenario: First visit
- **WHEN** a reader opens the hub without independent company records
- **THEN** available opportunities and factual screening reasons are displayed and automatic collection and analysis are scheduled
#### Scenario: Model unavailable
- **WHEN** a model request fails
- **THEN** supported source announcements remain collectable using conservative source-supported extraction and the existing feed stays readable
#### Scenario: Private project evidence
- **WHEN** a report was produced using another viewer’s projects or its project permissions or inputs have changed
- **THEN** its private output and score are withheld from this viewer, and eligible stale analysis is automatically refreshed
#### Scenario: Existing source preference
- **WHEN** automatic initialization runs after an administrator disabled a source
- **THEN** the disabled source remains disabled
#### Scenario: Opportunity card time visibility
- **WHEN** the reader views an opportunity in the dynamic feed
- **THEN** registration start, registration end and submission deadline are separately visible in Beijing time, using only the latest source evidence; missing or obscured values are marked pending verification, and date-only evidence does not invent an hour

### Requirement: Ready-to-read local procurement briefs
The system SHALL automatically provide source-and-project cross-check briefs on the opportunity feed and detail pages without requiring profile setup or manual research submission. The brief SHALL identify its deterministic nature and preserve source limitations.
#### Scenario: External project analysis not authorized
- **WHEN** sending private project data to a model destination is not authorized
- **THEN** model calls containing that projection are blocked while local briefs and public-source discovery remain usable
#### Scenario: Historical and counterexample records
- **WHEN** an announcement explicitly ended or a project remark records a lost bid
- **THEN** the brief retains that evidence and does not describe the closed announcement as currently open or the project as a won bid

### Requirement: Evidence-backed follow-up decision report
The system SHALL publish audited matching reasons, qualification gaps, counterexamples, price reference basis and a concrete action checklist with registration and submission deadlines. Unknown registration status SHALL NOT be inferred open from a future submission deadline.
#### Scenario: Incomplete historical price
- **WHEN** only budget, candidate quote, rent total or incomplete final award data is available
- **THEN** the report identifies its type, sources and missing basis, without presenting it as a comparable purchase unit price
#### Scenario: Existing project evidence
- **WHEN** project records contain similar work or a lost-bid remark
- **THEN** matching and counterexamples are cited as project facts, without inventing won-bid history or qualification compliance

### Requirement: Cost-controlled Aimon pilot and twelve-hour refresh
The system SHALL use the authorized Aimon endpoint with gpt-5.6-luna and low reasoning for hub agents, cap inputs and outputs, and refresh public sources every twelve hours without changing other platform AI settings.
#### Scenario: Scheduled update
- **WHEN** the twelve-hour refresh is due
- **THEN** enabled sources are incrementally collected, unchanged evidence does not trigger repeated extraction, and affected reports are refreshed
#### Scenario: Source or model failure
- **WHEN** a provider fails during the pilot
- **THEN** existing notices and reports remain readable and failure is recorded without switching to an unapproved model or expanding ERP access

### Requirement: Expanded domestic and international sources
The system SHALL support approved source adapters for PowerChina, Shandong Expressway, Shudao, ADB, World Bank, UNGM, TED and UK Find a Tender, using bounded public requests and bilingual relevance screening. Unsupported or unreachable sources SHALL remain explicitly distinguishable from verified collection.
#### Scenario: Public structured data
- **WHEN** a supported public API or listing returns an announcement
- **THEN** source evidence, source identifiers, original currency and deadline timezone are retained, unrelated navigation and category-only matches are excluded, and duplicate observations do not trigger repeated model extraction
#### Scenario: Overseas opportunity meaning
- **WHEN** a foreign notice describes a procurement plan, consultancy, works contract or contract award
- **THEN** its source stage and scope remain visible and it is not described as a currently open direct supply tender
#### Scenario: Bounded local acceptance
- **WHEN** source expansion is validated locally
- **THEN** each source reports actual fetched or failed samples separately from simulated tests, does not read or write ERP business data, and production collection settings remain unchanged
#### Scenario: Agent loop inspection
- **WHEN** an administrator checks hub operation
- **THEN** source freshness, queued or running age, terminal failures, audit correction rounds and model usage are inspectable without exposing credentials or changing ERP tasks
