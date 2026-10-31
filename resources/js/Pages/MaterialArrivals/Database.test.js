import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { randomUUID, createHash } from 'node:crypto';

const adapter = readFileSync('resources/material-arrivals/database-adapter.js', 'utf8');
const html = readFileSync('resources/material-arrivals/workbench.html', 'utf8');
const empty = { records: [], imports: [], revision: 0, updatedAt: null };
function setup(handler) {
    const fetch = vi.fn(async (url, options) => {
        const body = options.body ? JSON.parse(options.body) : undefined;
        if (url === '/state') return { ok: true, json: async () => ({ dataset: empty, preferences: {} }) };
        return handler(url, body);
    });
    const context = createContext({ fetch, structuredClone, crypto: { randomUUID }, MATERIAL_BOOT: { csrf: 'test-token', urls: { state: '/state', preferences: '/preferences', mutate: '/mutate', export: '/export' } } });
    context.window = context;
    runInContext(adapter, context);
    return { context, fetch, db: context.MaterialDatabase, store: context.createWorkbenchStore() };
}

describe('original workbench database bridge', () => {

 it('reuses startup data but refreshes subsequent reads',async()=>{const {store,fetch}=setup(()=>{});await store.read(true);expect(fetch).toHaveBeenCalledTimes(1);await store.read();expect(fetch).toHaveBeenCalledTimes(2);});
    it('acknowledges personal persistence only after the response and keeps failed data out of committed cache', async () => {
        let release;
        const { db } = setup(() => new Promise(resolve => { release = resolve; }));
        await db.ready;
        const saving = db.privateState.setItem('platform-draft', '{"plate":"A"}');
        await vi.waitFor(() => expect(release).toBeTypeOf('function'));
        expect(db.privateState.getItem('platform-draft')).toBeNull();
        release({ ok: true, json: async () => ({ revision: 1 }) });
        await saving;
        expect(db.privateState.getItem('platform-draft')).toBe('{"plate":"A"}');
        const failing = db.privateState.setItem('platform-draft', '{"plate":"B"}');
        await Promise.resolve(); await Promise.resolve();
        release({ ok: false, status: 409, json: async () => ({ message: 'stale draft' }) });
        await expect(failing).rejects.toThrow('stale draft');
        expect(db.privateState.getItem('platform-draft')).toBe('{"plate":"A"}');
    });

    it('serializes private writes using acknowledged revisions and scopes them without client user IDs', async () => {
        let revision = 0;
        const bodies = [];
        const { db } = setup((url, body) => {
            bodies.push(body);
            return { ok: true, json: async () => ({ revision: ++revision }) };
        });
        await db.ready;
        await Promise.all([db.privateState.setItem('platform-filters', '{}'), db.privateState.setItem('platform-filters', '{"months":[]}')]);
        expect(bodies.map(x => x.expected_revision)).toEqual([0, 1]);
        expect(bodies.every(x => x.key === 'filters' && !('user_id' in x))).toBe(true);
    });

    it('retries a failed transport with the same request identity instead of duplicating the mutation', async () => {
        const bodies = [];
        const { store } = setup((url, body) => {
            bodies.push(body);
            if (bodies.length === 1) throw Error('response lost');
            return { ok: true, json: async () => ({ dataset: { ...empty, revision: 1 }, result: null }) };
        });
        await store.ready;
        await store.save({ id: 'one' }, null, 0);
        expect(bodies).toHaveLength(2);
        expect(bodies[0]).toEqual(bodies[1]);
        expect(bodies[0].operation).toBe('save');
    });

    it('loads Excel tools only on demand and shares each library promise', async () => {
        const urls = [];
        const context = createContext({ document: { createElement: () => ({}), head: { append: (script) => { urls.push(script.src); queueMicrotask(() => script.onload()); } } } });
        context.window = context;
        const loader = [...html.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)].find((match) => match[1].includes('window.WorkbenchExcel='))[1];
        runInContext(loader, context);
        expect(urls).toEqual([]);
        await Promise.all([context.WorkbenchExcel.read(), context.WorkbenchExcel.read()]);
        expect(urls).toHaveLength(1);
        await context.WorkbenchExcel.write();
        expect(urls).toHaveLength(2);
        expect(urls.every((url) => url.startsWith('/workbench-assets/'))).toBe(true);
    });

    it('retains original classification and weight semantics, including mixed loads and zero denominator', () => {
        const context = createContext({}); context.window = context;
        const scripts = [...html.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)];
        const core = scripts.find(x => x[1].includes('function validateRecord'))[1];
        runInContext(core, context);
        const C = context.MaterialCore;
        expect(C.majorCategory({ items: [{ material: '槽钢' }, { material: '工字钢' }] })).toBe('型材');
        expect(C.category({ items: [{ material: '槽钢' }, { material: '工字钢' }] })).toBe('混装');
        expect(C.majorCategory({ items: [{ material: '槽钢' }, { material: '开平板' }] })).toBe('跨大类混装');
        expect(C.majorCategory({ items: [{ material: '槽钢' }, { material: '未知' }] })).toBe('待分类');
        expect(C.rate(0, 1)).toBeNull();
        expect(C.net({ gross: 51.9, tare: 17 })).toBeCloseTo(34.9);
    });

    it('preserves the supplied demo CSS byte for byte and removes browser persistence and historical seed records', () => {
        const css = html.match(/<style>([\s\S]*?)<\/style>/)[1];
        expect(createHash('sha256').update(css).digest('hex')).toBe('da21b81a9508f47c3f878c6dd3bbe76c42934df831c9dff242a52f5401c46dc7');
        expect(html).not.toMatch(/indexedDB\.open|localStorage\.|seed-39/);
        expect([...html.matchAll(/class="nav(?: active)?" data-view="(.*?)"/g)].map(x => x[1])).toEqual(['ledger', 'entry', 'summary', 'report']);
    });
});
