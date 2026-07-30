import { describe, expect, it } from 'vitest';
import {
  APPROVED_ONLINE_REGRESSION_ORIGIN,
  loadOnlineRegressionConfig,
} from './online-regression-config.mjs';

const validEnvironment = {
  ONLINE_REGRESSION_ENABLED: '1',
  ONLINE_REGRESSION_ALLOW_MUTATIONS: '1',
  ONLINE_REGRESSION_BASE_URL: APPROVED_ONLINE_REGRESSION_ORIGIN,
  ONLINE_REGRESSION_RUN_ID: 'REG-20260731-safe',
  ONLINE_REGRESSION_PASSWORD: 'process-only-secret',
};

describe('online regression configuration', () => {
  it('accepts an explicitly authorized fixed target', () => {
    expect(loadOnlineRegressionConfig(validEnvironment)).toMatchObject({
      baseUrl: APPROVED_ONLINE_REGRESSION_ORIGIN,
      runId: 'REG-20260731-safe',
      password: 'process-only-secret',
    });
  });

  it.each([
    ['missing enablement', { ONLINE_REGRESSION_ENABLED: '0' }],
    ['missing mutation authorization', { ONLINE_REGRESSION_ALLOW_MUTATIONS: '0' }],
    ['wrong target', { ONLINE_REGRESSION_BASE_URL: 'https://example.com' }],
    ['unsafe run id', { ONLINE_REGRESSION_RUN_ID: 'latest' }],
    ['development password', { ONLINE_REGRESSION_PASSWORD: 'password123' }],
  ])('rejects %s', (_label, override) => {
    expect(() => loadOnlineRegressionConfig({
      ...validEnvironment,
      ...override,
    })).toThrow();
  });
});
