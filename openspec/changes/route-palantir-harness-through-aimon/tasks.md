## 1. Provider contract

- [x] 1.1 Add isolated Aimon URL, key, model, and storage configuration.
- [x] 1.2 Preserve the Ark provider and make missing-key feedback provider-neutral.
- [x] 1.3 Document the deployment variables without credentials.

## 2. Regression coverage

- [x] 2.1 Add PHPUnit coverage for Aimon driver, endpoint, storage, and model selection.
- [x] 2.2 Run focused PHPUnit, Pint, strict OpenSpec validation, and the full quality gate.

## 3. Production canary and switch

- [x] 3.1 Issue a dedicated Palantir production key after action-time confirmation.
- [x] 3.2 Verify authenticated model discovery, plain Responses completion, and function-call continuation.
- [x] 3.3 Deploy with an environment backup, switch the provider, rebuild config cache, and restart workers.
- [x] 3.4 Verify the web and Feishu agent flows, Aimon trace attribution, and application health; roll back to Ark on failure.
