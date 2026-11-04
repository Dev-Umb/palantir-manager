// @vitest-environment node
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { JSDOM } from 'jsdom';
import { afterEach, describe, expect, it, vi } from 'vitest';

const html = readFileSync(new URL('../../production-workbench/workbench.html', import.meta.url), 'utf8');
const scripts = [...html.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)].map(match => match[1]);
const context = vm.createContext({});
vm.runInContext(scripts.find(code => code.startsWith('window.PRODUCTION_INTERFACE_HTML=')).split(';\n')[0].replace('window.', 'globalThis.'), context);
const interfaceHtml = context.PRODUCTION_INTERFACE_HTML;
const core = scripts.find(code => code.includes("const kinds=['台车模板','开挖台架','液压配件']"));
let dom;
afterEach(() => dom?.window.close());

function setup(rows = []) {
    dom = new JSDOM(interfaceHtml, { url: 'https://example.test/', runScripts: 'outside-only', pretendToBeVisual: true });
    const w = dom.window;
    w.scrollTo = vi.fn();
    w.HTMLElement.prototype.scrollIntoView = vi.fn();
    w.MaterialCore = { date: () => true };
    w.eval(core);
    const record = (id, extra) => ({
        ...Object.fromEntries(w.ProgressCore.fields[extra.kind || '台车模板'].map(([key, , type]) => [key, type === 'number' ? null : ''])),
        id, seq: Number(id), version: 1, kind: '台车模板', project: '项目' + id, type: '标准模板',
        progress: '未出图', shipping: '未发', ...extra,
    });
    const dataset = { progressRecords: rows.map((row, i) => record(String(i + 1), row)) };
    w.MaterialBridge = { getData: () => dataset, updateSaveStatus: vi.fn(), refresh: vi.fn(), store: {} };
    w.ProgressFiles = {};
    w.ProductionDatabase = { privateState: { getItem: () => null, setItem: vi.fn(), removeItem: vi.fn() } };
    w.WorkbenchHost = {};
    const script = [...w.document.querySelectorAll('script')].find(node => node.textContent.includes('const B=parent.MaterialBridge'));
    w.eval(script.textContent);
    return { w, doc: w.document, dataset };
}
const fixture = [
    { progress: '已做完', shipping: '已发', quantity: 0, estimated: 10 },
    { progress: '生产中', shipping: '部分已发', quantity: 2, estimated: 20 },
    { progress: '已下放', quantity: null, estimated: 30 },
    { progress: '未出图', quantity: 1, estimated: 40, subproject: '隧道甲' },
    { progress: '已做完', quantity: 3, estimated: 50 },
    { progress: '未出图', deleted: true, estimated: 999 },
];

describe('November production interface', () => {
    it('prioritizes four non-additive follow-up queues and keeps queue selection after detail return', async () => {
        const { w, doc } = setup(fixture);
        expect(doc.querySelectorAll('[data-queue]')).toHaveLength(4);
        expect(doc.querySelector('[data-queue="drawing"] b').textContent).toContain('1');
        expect(doc.querySelector('[data-queue="production"] b').textContent).toContain('1');
        expect(doc.querySelector('[data-queue="partial"] b').textContent).toContain('1');
        expect(doc.querySelector('#volume-comparison')).toBeNull();
        expect(doc.querySelector('.priority-followup').compareDocumentPosition(doc.querySelector('.category-grid')) & 4).toBe(4);
        doc.querySelector('[data-queue="drawing"]').click();
        expect(doc.querySelector('#followup-body').textContent).toContain('隧道甲');
        doc.querySelector('[data-queue-open]').click();
        await w.FormalUI.navigate('dashboard');
        expect(doc.querySelector('[data-queue="drawing"]').getAttribute('aria-pressed')).toBe('true');
        expect(doc.querySelector('#followup-body').textContent).toContain('项目4');
    });

    it('uses disjoint Excel groups, retains search and resets only the other filters', async () => {
        const { w, doc } = setup(fixture);
        await w.FormalUI.navigate('台车模板');
        expect([...doc.querySelectorAll('[data-quick] b')].map(x => Number(x.textContent))).toEqual([5, 1, 3, 1]);
        const search = doc.querySelector('#search');
        search.value = '项目'; search.dispatchEvent(new w.Event('input'));
        const stage = doc.querySelector('#filter-stage'); stage.value = '已做完'; stage.dispatchEvent(new w.Event('change'));
        doc.querySelector('[data-quick="1"]').click();
        expect(search.value).toBe('项目');
        expect(stage.value).toBe('');
        expect(doc.querySelector('#filter-shipping').value).toBe('');
        expect([...doc.querySelectorAll('#list-body tr')].map(x => x.dataset.record)).toEqual(['2', '3', '5']);
        await w.FormalUI.navigate('台车模板', { dimension: 'pending' });
        doc.querySelector('[data-quick="0"]').click();
        expect(doc.querySelector('#clear-dimension')).toBeNull();
        expect(doc.querySelector('#list-body tr').dataset.record).toBe('1');
    });

    it('distinguishes missing and zero quantities, sorts numerically and preserves hydraulic detail units', async () => {
        const { w, doc } = setup([
            ...fixture,
            { kind: '液压配件', hydraulic: null, screws: 90, pins: 80, jacks: 70 },
            { kind: '液压配件', hydraulic: 0, screws: 20 },
            { kind: '液压配件', hydraulic: 2 },
        ]);
        await w.FormalUI.navigate('台车模板');
        doc.querySelector('[data-sort="quantity"]').click();
        expect([...doc.querySelectorAll('.quantity-cell')].map(x => x.textContent)).toEqual(['0', '1', '2', '3', '—']);
        await w.FormalUI.navigate('液压配件');
        expect(doc.querySelector('[data-sort="subproject"]')).toBeNull();
        doc.querySelector('[data-sort="hydraulic"]').click();
        expect([...doc.querySelectorAll('.quantity-cell')].map(x => x.textContent)).toEqual(['0', '2', '—']);
        doc.querySelector('[data-open="7"]').click();
        expect(doc.querySelector('#field-screws').value).toBe('90');
        expect(doc.querySelector('#field-pins').value).toBe('80');
        expect(doc.querySelector('#field-jacks').value).toBe('70');
        expect(w.ProgressCore.fields['液压配件'].map(x => x[0])).toEqual(expect.arrayContaining(['screws', 'pins', 'jacks']));
    });

    it('keeps zero and missing estimates distinct without claiming a total percentage', () => {
        const { doc } = setup([{ estimated: 0 }, { kind: '开挖台架', estimated: null }]);
        const cards = [...doc.querySelectorAll('.category-card')];
        expect(cards[0].textContent).toContain('0.00');
        expect(cards[1].textContent).toContain('未填写');
        expect(cards[0].textContent).not.toContain('100%');
        expect(cards[1].textContent).not.toContain('100%');
    });
});
