## Why

Palantir's production AI assistant currently calls the Ark endpoint directly. The organization now operates Aimon as its managed OpenAI-compatible gateway and wants Palantir's existing Harness agents to use the routable `gpt-5.6-sol` alias through that gateway.

## What Changes

- Add an explicit Aimon provider backed by the Laravel AI OpenAI Responses driver.
- Make the Aimon URL, API key, and public model alias independently configurable.
- Switch production to the Aimon provider only after authenticated model, response, tool-call, and Feishu end-to-end checks pass.
- Keep the existing Ark provider configured as the immediate rollback path.

## Capabilities

### New Capabilities

- `aimon-harness-routing`: Covers the Aimon provider contract, model selection, credential boundary, compatibility checks, and rollback.

### Modified Capabilities

- None.

## Impact

- Affects AI provider configuration and the generic missing-provider-credential message.
- Does not change agent instructions, tools, conversations, permissions, business data, Feishu behavior, schemas, dependencies, or ai-review-manager code.
