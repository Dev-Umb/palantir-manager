## Why

Transient Ark failures after successful tool calls currently become user-visible `分析失败` whenever the model has already streamed transition text. The same failure also loses the provider HTTP response details needed to distinguish rate limits, quota rejection, and invalid requests.

## What Changes

- Retry recoverable AI provider failures within the existing three-attempt job budget even when the failed attempt streamed partial text.
- Reset partial answer and projected tool output before the retry is presented to the user.
- Persist and log only a bounded, sanitized provider HTTP status and response excerpt for diagnosis.
- Preserve terminal behavior for non-recoverable failures and exhausted attempts.

## Capabilities

### New Capabilities

- `resilient-ai-run-recovery`: Covers partial-output retries, retry state replacement, bounded provider diagnostics, and preserved terminal failure behavior.

### Modified Capabilities

- None.

## Impact

- Affects `RunAiHarness`, `AiFailureClassifier`, AI run event reduction, and their PHPUnit/Vitest regression coverage.
- Does not change provider credentials, Ark endpoint/model, queue concurrency, retry count, permissions, tools, business data, dependencies, or schema.
