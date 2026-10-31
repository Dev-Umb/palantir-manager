## ADDED Requirements
### Requirement: Workbench return navigation
The material arrivals workbench SHALL expose a visible return link to the existing Palantir dashboard in the top-level browsing context, without changing dashboard authorization or bypassing unsaved-work warnings.

#### Scenario: Return from embedded workbench
- **WHEN** an authenticated workbench user activates the return link
- **THEN** the top-level window navigates to the named dashboard route and retains existing authorization checks

#### Scenario: Preserve workbench capabilities
- **WHEN** a user opens the workbench
- **THEN** all four original business pages and database persistence remain available subject to existing permissions
