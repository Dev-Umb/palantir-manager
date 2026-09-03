## Context

Both the web AI assistant and Feishu bot resolve `ai.default` and run the existing `XycDataAgent` or `FeishuDataAgent`. Aimon exposes an authenticated OpenAI-compatible `/v1/responses` surface and a public `gpt-5.6-sol` alias. The provider switch must therefore happen below the agent layer and must prove multi-turn tool-call compatibility, not only plain text completion.

## Goals / Non-Goals

**Goals:**

- Route both existing Harness agents through Aimon with `gpt-5.6-sol`.
- Keep provider credentials isolated from the direct OpenAI and Ark settings.
- Validate authenticated model discovery, plain response, function call continuation, and Feishu delivery before accepting production.
- Preserve a fast rollback to the current Ark configuration.

**Non-Goals:**

- Rebuild or rename the Harness architecture.
- Change agent prompts, tools, data access, chat rendering, reminder delivery, queue policy, or conversation storage.
- Modify Aimon routing, aliases, upstream pools, quotas, or source code.

## Decisions

### Use a dedicated Aimon provider

Configure `aimon` with Laravel AI's standard `openai` driver, `store=false`, the Aimon `/v1` base URL, and the public `gpt-5.6-sol` alias for all text model tiers. Dedicated `AIMON_*` variables prevent accidental coupling to direct OpenAI configuration.

### Switch below the agent layer

Continue passing `config('ai.default')` from both web and queued Feishu entry points. No agent or tool implementation changes are required.

### Fail closed and preserve Ark rollback

Production switches only after an independently issued Aimon key passes the compatibility matrix. A backup of the production environment and the unchanged Ark provider allow immediate rollback by restoring `AI_PROVIDER=ark`, clearing cached configuration, and restarting workers.

## Risks / Trade-offs

- [Aimon returns subtly different Responses events] -> Require a real function-call plus function-call-output continuation before switching.
- [Credential is unavailable after creation] -> Capture the one-time value directly into protected deployment configuration without logging or committing it.
- [Model alias or upstream health changes] -> Verify `/v1/models` and the live Aimon model-capability page immediately before deployment.
- [Queued workers retain the old provider] -> Restart workers after configuration caching and verify a new Feishu run in Aimon traces.

## Migration Plan

1. Deploy the provider configuration while leaving `AI_PROVIDER=ark`.
2. Issue a dedicated unlimited Aimon key for Palantir production.
3. Run authenticated model, response, and tool-call continuation canaries.
4. Back up production `.env`, set the `AIMON_*` values and `AI_PROVIDER=aimon`, then rebuild config cache and restart workers.
5. Run web and Feishu end-to-end checks and inspect both application logs and Aimon traces.
6. On failure, restore `AI_PROVIDER=ark`, clear/cache configuration, restart workers, and retain the Aimon key for diagnosis or revoke it separately.
