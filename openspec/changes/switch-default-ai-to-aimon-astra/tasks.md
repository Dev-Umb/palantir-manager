## 1. Validate and activate

- [x] Inspect production run failure and current provider configuration.
- [x] Confirm exact Aimon model alias without exposing credentials.
- [x] Validate the OpenSpec change before activation.
- [x] Verify synthetic streaming, tool execution, and preserved follow-up context.
- [x] Preserve previous non-secret settings, change only authorized defaults, and reload consumers.
- [x] Verify effective configuration and a post-switch default invocation.
- [x] Run strict validation and the local quality gate; distinguish pre-existing workspace failures from deployment evidence.
- [x] Report affected-user historical retry as a separate remaining verification.

## Evidence (2026-09-10, UTC+8)

- Original failure: run `01a088a8-9bf2-7256-a85d-f26267f20468`, 08:12:26–08:12:32, Ark HTTP 400 InvalidParameter on all three attempts. Exact rejected parameter was not named by the provider.
- Synthetic tool stream passed in 6.16 seconds; tool-history follow-up passed in 3.58 seconds. No business data or saved conversation was used in these probes.
- Only production AI_PROVIDER and AIMON_MODEL changed; old non-secret values are recorded in `storage/app/deploy-backups/ai-default-20260910-astra/previous-nonsecret-settings.json` on the server.
- Cached defaults verified as aimon / gpt-6-astra. AI workers, integrations worker, scheduler and PHP-FPM active after refresh; login HTTP 200.
- Default invocation without explicit provider/model returned the expected synthetic marker in 2.33 seconds. Aimon trace `01a089c7-8b54-743e-8f82-79077f0f30f3`, 13:25:51, key name `Palantir Production Harness`, gpt_pool, gpt-6-astra, success / HTTP 200.
- OpenSpec strict validation: 16 passed, 0 failed. Local quality gate ran and failed at existing application tests: 61 tests, 6 passed, 55 errors because SQLite lacks users.deleted_at. No local application code was deployed, committed, or changed by this task.
- Private historical-conversation replay was rejected by automatic approval review because authorization did not explicitly cover sending that historical payload to Aimon. Synthetic validation completed instead; affected-user historical retry and real Feishu delivery remain unverified.
