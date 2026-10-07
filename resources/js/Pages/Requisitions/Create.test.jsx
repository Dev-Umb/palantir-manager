// @vitest-environment node
import { existsSync } from 'node:fs';
import { expect, it } from 'vitest';

it('keeps the retired page unavailable and its current workspace present', () => {
    expect(existsSync(new URL('./Create.jsx', import.meta.url))).toBe(false);
    expect(existsSync(new URL('../Ontology/Index.jsx', import.meta.url))).toBe(true);
});
