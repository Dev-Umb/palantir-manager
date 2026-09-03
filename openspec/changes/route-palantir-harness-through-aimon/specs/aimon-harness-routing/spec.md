## ADDED Requirements

### Requirement: Harness agents use an isolated Aimon provider

The system MUST provide an Aimon text provider using the OpenAI Responses protocol, a separately configured Aimon credential and base URL, disabled provider-side storage, and the public `gpt-5.6-sol` alias as the default, cheapest, and smartest text model.

#### Scenario: Aimon is selected as the default provider

- **WHEN** `AI_PROVIDER` is set to `aimon`
- **THEN** both web and Feishu Harness runs use the Aimon base URL and `gpt-5.6-sol` without changing their agents or tools

#### Scenario: Aimon credential is absent

- **WHEN** Aimon is selected but `AIMON_API_KEY` is blank
- **THEN** the request fails closed with a provider-neutral configuration error and does not fall back silently

### Requirement: Production switch requires protocol-level compatibility

The production provider MUST NOT switch from Ark until the dedicated Aimon credential can list `gpt-5.6-sol`, complete a plain response, emit a function call, accept its function output, and complete the continuation.

#### Scenario: Plain response succeeds but tool continuation fails

- **WHEN** Aimon answers plain text but cannot complete a function-call continuation
- **THEN** production remains on Ark and the switch is reported as blocked

#### Scenario: Compatibility matrix succeeds

- **WHEN** model discovery, plain response, function call, and continuation all succeed
- **THEN** the production environment may select Aimon and restart workers

### Requirement: Existing capabilities and rollback remain intact

The provider change MUST preserve the existing Harness agents, tools, conversations, permissions, Feishu behavior, business data, and Ark provider configuration.

#### Scenario: Post-switch Feishu query invokes a data tool

- **WHEN** an authorized Feishu user asks a project-data question after the switch
- **THEN** the existing tool executes under the same data scope and the response is delivered to the originating chat

#### Scenario: Aimon fails after switching

- **WHEN** online verification detects a provider or protocol regression
- **THEN** operators can restore the previous Ark provider without a code or schema rollback
