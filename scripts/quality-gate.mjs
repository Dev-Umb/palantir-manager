import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const CHECKS = {
  stagedDiff: {
    label: 'Staged diff',
    command: ['git', 'diff', '--cached', '--check'],
  },
  openSpec: {
    label: 'OpenSpec strict validation',
    command: ['composer', 'openspec:validate'],
  },
  phpunit: {
    label: 'PHPUnit application tests',
    command: ['composer', 'test:application'],
  },
  frontend: {
    label: 'Frontend component tests',
    command: ['npm', 'run', 'test:ui'],
  },
  build: {
    label: 'Production frontend build',
    command: ['npm', 'run', 'build'],
  },
};

const backendPrefixes = [
  'app/',
  'bootstrap/',
  'config/',
  'database/',
  'routes/',
  'tests/',
];

const frontendPrefixes = [
  'resources/',
  'scripts/online-regression-',
  'scripts/public-regression.mjs',
  'scripts/quality-gate.',
];

const governancePrefixes = [
  '.githooks/',
  'openspec/',
  'scripts/online-regression-',
  'scripts/public-regression.mjs',
  'scripts/quality-gate.',
];

const exactBackendPaths = new Set([
  'artisan',
  'composer.json',
  'composer.lock',
  'phpunit.xml',
]);

const exactFrontendPaths = new Set([
  'composer.json',
  'composer.lock',
  'package.json',
  'package-lock.json',
  'vite.config.js',
  'vitest.config.js',
]);

const exactGovernancePaths = new Set([
  'AGENTS.md',
  'composer.json',
  'composer.lock',
  'package.json',
  'package-lock.json',
  'vitest.config.js',
]);

function startsWithAny(file, prefixes) {
  return prefixes.some((prefix) => file.startsWith(prefix));
}

export function selectChecks(files) {
  const selected = new Set(['stagedDiff']);
  let recognizedFiles = 0;

  for (const file of files) {
    const isBackend = exactBackendPaths.has(file) || startsWithAny(file, backendPrefixes);
    const isFrontend = exactFrontendPaths.has(file) || startsWithAny(file, frontendPrefixes);
    const isGovernance = exactGovernancePaths.has(file)
      || startsWithAny(file, governancePrefixes);
    const isDocumentation = file === 'README.md' || file.startsWith('docs/');

    if (isBackend) {
      selected.add('phpunit');
      recognizedFiles += 1;
    }

    if (isFrontend) {
      selected.add('frontend');
      selected.add('build');
      recognizedFiles += 1;
    }

    if (isGovernance) {
      selected.add('openSpec');
      recognizedFiles += 1;
    }

    if (isDocumentation) {
      recognizedFiles += 1;
    }
  }

  if (recognizedFiles < files.length) {
    selected.add('openSpec');
    selected.add('phpunit');
    selected.add('frontend');
    selected.add('build');
  }

  return [...selected];
}

function stagedFiles() {
  const result = spawnSync(
    'git',
    ['diff', '--cached', '--name-only', '--diff-filter=ACMR'],
    { encoding: 'utf8' },
  );

  if (result.status !== 0) {
    throw new Error(result.stderr.trim() || 'Unable to inspect staged files.');
  }

  return result.stdout.split('\n').map((file) => file.trim()).filter(Boolean);
}

function runCheck(checkId) {
  const check = CHECKS[checkId];
  const [command, ...args] = check.command;

  console.log(`\n[quality-gate] ${check.label}: ${check.command.join(' ')}`);

  const result = spawnSync(command, args, {
    stdio: 'inherit',
    env: process.env,
  });

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0) {
    throw new Error(`${check.label} failed with exit code ${result.status}.`);
  }
}

export function main(argumentsList) {
  const runAll = argumentsList.includes('--all');
  const runStaged = argumentsList.includes('--staged');

  if (runAll === runStaged) {
    throw new Error('Choose exactly one quality gate mode: --all or --staged.');
  }

  const files = runStaged ? stagedFiles() : [];
  const checkIds = runAll
    ? ['stagedDiff', 'openSpec', 'phpunit', 'frontend', 'build']
    : selectChecks(files);

  if (runStaged && files.length === 0) {
    console.log('[quality-gate] No staged files.');

    return;
  }

  console.log(`[quality-gate] Selected checks: ${checkIds.join(', ')}`);

  for (const checkId of checkIds) {
    runCheck(checkId);
  }
}

const invokedPath = process.argv[1];

if (invokedPath && fileURLToPath(import.meta.url) === path.resolve(invokedPath)) {
  try {
    main(process.argv.slice(2));
  } catch (error) {
    console.error(`[quality-gate] ${error.message}`);
    process.exitCode = 1;
  }
}
