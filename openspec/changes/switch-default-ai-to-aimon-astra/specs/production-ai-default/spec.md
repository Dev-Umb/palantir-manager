## ADDED Requirements

### Requirement: Production defaults to the requested Aimon model

The production assistant MUST use the existing Aimon provider with model gpt-6-astra for new default AI invocations after configuration refresh.

#### Scenario: Default request starts after the switch

- **WHEN** a web or Feishu AI job starts without an explicit provider override
- **THEN** its effective default provider is aimon and its default text model is gpt-6-astra

### Requirement: Activation preserves adjacent behavior

The switch MUST preserve business data, permissions, tools, existing conversation records, queue concurrency, and credentials. The prior non-secret provider and model values MUST be available for rollback.

#### Scenario: Configuration is refreshed

- **WHEN** the new defaults are activated and consumers reload configuration
- **THEN** only the authorized provider and model settings change and existing business records and conversation history remain intact

### Requirement: Compatibility evidence is explicit

Activation MUST be preceded by successful synthetic streaming, tool-call, and follow-up checks against gpt-6-astra. Those checks MUST NOT be represented as proof of successful retry of a private historical conversation.

#### Scenario: Synthetic checks pass

- **WHEN** a fresh synthetic tool call and a follow-up return their expected values
- **THEN** streaming and synthetic continuation are verified while affected-user historical retry remains unverified
