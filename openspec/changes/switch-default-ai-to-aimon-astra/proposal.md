## Why

The production assistant currently uses Ark. On 2026-09-10 a follow-up request failed three times with HTTP 400 InvalidParameter, so no corresponding request reached Aimon. The user explicitly requested switching the default to Aimon GPT Astra.

## What Changes

- Set production AI_PROVIDER to aimon and AIMON_MODEL to gpt-6-astra using the existing configured provider and credential.
- Verify synthetic streaming, tool calls, and follow-up context before enabling the default, then refresh cached configuration and long-running consumers.
- Preserve business records, permissions, tools, conversation history, endpoints, dependencies, and application code.
- Do not resend private historical conversations as diagnostics without authorization. Existing affected-user retry remains a separate verification boundary.

## Capabilities

### New Capabilities

- `production-ai-default`: Defines the explicitly requested production default and verification boundary.

### Modified Capabilities

- None.

## Impact

Production environment configuration and worker configuration reload only. No schema, route, dependency, or application code changes. Both web and Feishu AI jobs consume the existing shared default. Preserve previous non-secret values for rollback; credentials remain only in their existing secret store.

## Approval

The user authorized application in this task: 默认切换使用aimon的gpt astra. This is not proposal-only work.
