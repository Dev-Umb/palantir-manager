import path from 'node:path';

export const APPROVED_ONLINE_REGRESSION_ORIGIN = 'https://palantir.umb.ink';

function requireValue(environment, name) {
  const value = environment[name]?.trim();

  if (!value) {
    throw new Error(`Online regression requires ${name}.`);
  }

  return value;
}

export function loadOnlineRegressionConfig(environment) {
  if (environment.ONLINE_REGRESSION_ENABLED !== '1') {
    throw new Error('Online regression is disabled. Set ONLINE_REGRESSION_ENABLED=1 explicitly.');
  }

  if (environment.ONLINE_REGRESSION_ALLOW_MUTATIONS !== '1') {
    throw new Error(
      'This regression creates online records. Set ONLINE_REGRESSION_ALLOW_MUTATIONS=1 explicitly.',
    );
  }

  const baseUrl = requireValue(environment, 'ONLINE_REGRESSION_BASE_URL').replace(/\/+$/, '');

  if (baseUrl !== APPROVED_ONLINE_REGRESSION_ORIGIN) {
    throw new Error(
      `Online regression target must be exactly ${APPROVED_ONLINE_REGRESSION_ORIGIN}.`,
    );
  }

  const runId = requireValue(environment, 'ONLINE_REGRESSION_RUN_ID');

  if (!/^REG-[A-Za-z0-9-]{8,64}$/.test(runId)) {
    throw new Error(
      'ONLINE_REGRESSION_RUN_ID must start with REG- and contain 8-64 letters, numbers, or hyphens.',
    );
  }

  const password = requireValue(environment, 'ONLINE_REGRESSION_PASSWORD');

  const reportPath = environment.ONLINE_REGRESSION_REPORT_PATH?.trim()
    || path.join('docs', `public-regression-${runId}.md`);

  return {
    baseUrl,
    password,
    runId,
    reportPath,
  };
}
