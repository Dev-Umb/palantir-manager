## ADDED Requirements

### Requirement: Engineering access to one shared library
The module SHALL use existing platform authentication and effective permissions with support-library.view, support-library.manage and support-library.export. Engineering users SHALL receive all three by default, with admin following the existing all-permission convention. Other roles and restrictive profiles MUST retain their existing scope.

#### Scenario: Technical user maintains shared data
- **WHEN** an authorized engineering user saves a valid library change
- **THEN** other authorized users read the same saved version
- **AND** existing project, contract, finance and timebook permissions remain unchanged

#### Scenario: Direct request without permission
- **WHEN** a user lacks view or the additional permission required for a write or export
- **THEN** the server rejects the request without disclosing or changing library data

### Requirement: Preserve demo content and interaction
The page SHALL preserve the current demo overview/actions, inline import/editor, combination inputs and scope note, result metrics/table, and catalog in the same content order while using the platform visual system. Every current visible capability MUST have its mapped carrier in the PRD.

#### Scenario: Initial visit and sample input
- **WHEN** the user first opens the module or selects one of its six examples
- **THEN** the initial conditions remain unselected, the default or selected expression is previewed, and no unsubmitted result can be exported

### Requirement: Strict combination grammar and independent entries
The parser SHALL accept ordered positive millimetre-precision metre dimensions, plus-delimited segments/spans, slash group counts defaulting to one, the legacy final-at count, and current independent-item separators. It MUST reject invalid or unsafe counts without truncation. All-column entries SHALL omit the brace-model requirement.

#### Scenario: Independent columns and mixed entries
- **WHEN** the user enters 6/2 6/4
- **THEN** the result contains six independent six-metre columns and no inter-item joints
- **AND** changing an entry to include a grid requires a brace model for that entry

### Requirement: Full-grid quantity expansion
The engine SHALL compute column positions as (nx+1)*(ny+1)*groups and expand each height segment separately. Transverse faces per bay SHALL equal (ny+1)*groups and longitudinal faces SHALL equal (nx+1)*groups. Repeated dimensions MUST remain repeated.

#### Scenario: Multi-bay stacked grid
- **WHEN** lifting C14A diameter273 is queried with 6+5@2+3@3+3/4 against the baseline
- **THEN** it returns36positions,72column segments,96segmented faces and480braces

### Requirement: Component identity and catalog
Merged columns SHALL retain source identity, and braces SHALL be keyed by model and source identity without merging different hole variants. Steel columns SHALL precede length-ascending braces. The catalog SHALL filter by the first entry's use and diameter only.

#### Scenario: Two brace models
- **WHEN** two entries each request 6@2@3/4 with C14A and C16A respectively
- **THEN** columns merge but brace models remain separate, totaling32columns and160braces

### Requirement: Weight and internal-joint bolt semantics
The engine SHALL prefer valid original mass, otherwise use one channel per brace at14.535kg/m for C14A or17.240kg/m for C16A. Bolts SHALL use recorded per-piece brace quantities and exactly one count per internal column joint, excluding bottom and top ends. Bolt mass SHALL be0.44kg/set. Null MUST remain distinct from zero.

#### Scenario: Baseline complete result
- **WHEN** lifting C14A diameter273 is queried with 6@2@3/4
- **THEN** it returns16columns,80braces,512bolt sets and10012.893920kg estimated mass including bolts, displayed as10012.9kg

#### Scenario: Joint count conflict
- **WHEN** adjacent column segments have inconsistent recorded bolt quantities
- **THEN** the query is blocked rather than selecting or summing the conflicting values

### Requirement: Explicit partial and hard-error results
Missing individual brace matches SHALL preserve matched components and columns while retaining null complete totals and explicit missing locations. Invalid syntax, missing or ambiguous steel columns, missing use/diameter candidate pools, unsafe arithmetic and joint conflicts MUST block the entire query. Column-only queries SHALL not require a brace candidate pool.

#### Scenario: Missing short-height brace matches
- **WHEN** the baseline is queried with 6+5+0.7@2+3@3+3/4 under lifting C14A diameter273
- **THEN** it returns108columns,480matched braces,4missing matches and48missing faces
- **AND** complete bolt count and weight remain null while accounted weight is52255.858920kg

### Requirement: Validated shared-library editing
The module SHALL provide current part, frame and composition fields with immutable saved tracking IDs, valid natural keys and complete associations. Unsaved drafts MUST NOT affect queries or shared state, and failed saves MUST preserve the current version and draft.

#### Scenario: Cancel or invalid references
- **WHEN** the user cancels a draft or attempts to save a dangling composition reference
- **THEN** the saved library remains unchanged and the invalid save identifies the offending record

### Requirement: Previewed atomic Excel merge
Import SHALL recognize current part and frame headers, support merged cells, preserve holes and unknowns, and reject unsafe files, formulas, duplicate keys and invalid associations. Confirmation SHALL update matching components and whole matching-frame compositions, including null overwrites, while preserving absent records. Preview MUST NOT mutate the active library.

#### Scenario: Repeat import with null fields
- **WHEN** a valid repeated workbook is previewed and confirmed with an empty component mass
- **THEN** matching counts do not multiply, the mass becomes unknown, and records absent from the workbook remain

#### Scenario: Stale preview
- **WHEN** another user updates the library after preview
- **THEN** confirmation rejects the stale version without changing the active library

### Requirement: Original export capabilities and truthful saving
The module SHALL retain a one-sheet nine-column result XLSX, a two-data-sheet library XLSX, a blank template with instructions, and an offline HTML snapshot. Result exports SHALL bind to validated conditions and a consistent library version. Saving messages MUST distinguish confirmed write completion, cancellation, failure and download initiation.

#### Scenario: Partial result export
- **WHEN** a result has missing brace matches
- **THEN** the filename, sheet name and totals identify partial/accounted scope without populating complete totals

#### Scenario: Browser download fallback
- **WHEN** the browser cannot directly confirm a completed file write
- **THEN** the UI reports download initiation rather than successful disk saving, and cancellation does not trigger automatic download

### Requirement: Offline snapshot preserves provenance without platform credentials
Downloaded HTML SHALL preserve a saved library/rule version and standalone behavior, safely embed data, contain no platform credentials, and clearly state that local changes do not synchronize to the shared library. Download access MUST be checked on the server.

#### Scenario: Local editing after download
- **WHEN** a user edits a downloaded offline snapshot
- **THEN** only its local working copy changes and the platform shared library remains unchanged

### Requirement: Revision safety and traceable initialization
Library writes SHALL be atomic, version-checked and idempotent, with immutable snapshots and source metadata. Initialization MUST be restricted to an empty module and preserve the supplied52parts,30frames and58composition rows without replacing existing work.

#### Scenario: Concurrent editors
- **WHEN** two users submit different changes against the same revision
- **THEN** at most one commits and the other receives a conflict while retaining its draft

### Requirement: Stale-result protection and accessible layout
Changes to query inputs or active library state SHALL invalidate prior results and exports. Out-of-order responses MUST NOT restore stale data. Desktop and narrow layouts SHALL retain all controls, fields, scope notices and accessible feedback.

#### Scenario: Late query response
- **WHEN** an earlier response arrives after a newer query or input change
- **THEN** it is ignored and cannot enable export of the outdated result

### Requirement: Separate verification and delivery evidence
Implementation SHALL satisfy PRD FR-01 through FR-16 and acceptance AC-01 through AC-16, including retained adjacent behavior and collateral boundaries. Local tests, document validation, quality gate, commit, deployment and real-browser acceptance MUST be reported separately.

#### Scenario: Source toolkit tests pass before implementation
- **WHEN** the supplied toolkit tests pass in an isolated copy
- **THEN** the evidence is recorded as source-baseline verification and not as completion of the new platform module
