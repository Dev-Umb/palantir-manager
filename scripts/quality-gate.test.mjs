import { describe, expect, it } from 'vitest';
import { selectChecks } from './quality-gate.mjs';

describe('quality gate path selection', () => {
  it('runs only the diff check for ordinary documentation', () => {
    expect(selectChecks(['docs/testing/regression-and-quality-gates.md']))
      .toEqual(['stagedDiff']);
  });

  it('selects PHPUnit for backend changes', () => {
    expect(selectChecks(['app/Models/User.php']))
      .toEqual(['stagedDiff', 'phpunit']);
  });

  it('selects frontend tests and build for frontend changes', () => {
    expect(selectChecks(['resources/js/Pages/Dashboard.jsx']))
      .toEqual(['stagedDiff', 'frontend', 'build']);
  });

  it('selects strict OpenSpec validation for governance changes', () => {
    expect(selectChecks(['openspec/config.yaml']))
      .toEqual(['stagedDiff', 'openSpec']);
  });

  it('tests and validates quality gate tooling changes', () => {
    expect(selectChecks(['scripts/quality-gate.mjs']))
      .toEqual(['stagedDiff', 'frontend', 'build', 'openSpec']);
  });

  it('runs every core check when Composer entry points change', () => {
    expect(selectChecks(['composer.json']))
      .toEqual(['stagedDiff', 'phpunit', 'frontend', 'build', 'openSpec']);
  });

  it('fails closed for an unknown production or tooling path', () => {
    expect(selectChecks(['worker/runtime.ts']))
      .toEqual(['stagedDiff', 'openSpec', 'phpunit', 'frontend', 'build']);
  });
});
