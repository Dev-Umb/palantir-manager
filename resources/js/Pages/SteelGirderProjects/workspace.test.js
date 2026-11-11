// @vitest-environment node
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import { afterEach, expect, it, vi } from 'vitest';

const root = new URL('../../../steel-girder/', import.meta.url);
let dom;
afterEach(() => { dom?.window.close(); vi.restoreAllMocks(); });
function boot(capabilities = { manage: true, export: true }) {
    dom = new JSDOM(readFileSync(new URL('dashboard.html', root), 'utf8'), { runScripts: 'outside-only', url: 'https://local.test/steel-girder-projects/workspace' });
    const w = dom.window;
    w.matchMedia = () => ({ matches: true, addEventListener() {} });
    w.confirm = vi.fn(() => false);
    w.setImmediate = setImmediate; w.clearImmediate = clearImmediate;
    w.TextEncoder = TextEncoder; w.TextDecoder = TextDecoder;
    w.structuredClone = structuredClone;
    w.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
    w.HTMLDialogElement.prototype.close = function () { this.open = false; this.dispatchEvent(new w.Event('close')); };
    w.SteelGirderConfig = capabilities;
    w.MaterialProjectStore = { list: vi.fn(async () => []), get: vi.fn(), listDrawings: vi.fn(async () => []), save: vi.fn(async () => ({})), remove: vi.fn(), original: vi.fn() };
    w.eval(readFileSync(new URL('jszip.min.js', root), 'utf8'));
    w.eval(readFileSync(new URL('review-export.js', root), 'utf8'));
    const app = readFileSync(new URL('app.js', root), 'utf8').replace(/\}\)\(\);\s*$/, 'window.steelTest={openProject,importFile,readXlsx,parseWorkbook,detectScope,scaleModel,exportRows,showProjectHome};})();');
    w.eval(app);
    return w;
}
async function workbook(w, name = '示例单幅.xlsx', net = 0) {
    const zip = new w.JSZip();
    const ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    const c = (ref, val) => typeof val === 'number' ? `<c r="${ref}"><v>${val}</v></c>` : `<c r="${ref}" t="inlineStr"><is><t>${val}</t></is></c>`;
    zip.file('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
    zip.file('xl/workbook.xml', `<workbook xmlns="${ns}" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="钢箱梁明细表" sheetId="1" r:id="rId1"/><sheet name="材料汇总" sheetId="2" r:id="rId2"/></sheets></workbook>`);
    zip.file('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Target="styles.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"/></Relationships>');
    zip.file('xl/styles.xml', `<styleSheet xmlns="${ns}"><fonts count="1"><font/></fonts><fills count="1"><fill/></fills><borders count="1"><border/></borders><cellXfs count="1"><xf/></cellXfs></styleSheet>`);
    zip.file('xl/worksheets/sheet1.xml', `<worksheet xmlns="${ns}"><sheetData><row r="1">${c('A1', '名称')}${c('B1', '编号')}${c('C1', '规格')}${c('D1', '数量')}${c('E1', '单位')}</row><row r="2">${c('A2', '剪力钉')}${c('B2', 'JD')}${c('C2', 'M20')}${c('D2', 4)}${c('E2', '个')}</row></sheetData></worksheet>`);
    zip.file('xl/worksheets/sheet2.xml', `<worksheet xmlns="${ns}"><sheetData><row r="1">${c('A1', '已计重量合计')}${c('B1', 10)}</row><row r="2">${c('A2', '净重合计')}${net === null ? '' : c('B2', net)}</row><row r="3">${c('A3', '内表面涂装')}${c('B3', 2)}</row><row r="4">${c('A4', '外表面涂装')}${c('B4', 3)}</row></sheetData></worksheet>`);
    const buffer = await zip.generateAsync({ type: 'arraybuffer' });
    return { name, size: buffer.byteLength, arrayBuffer: async () => buffer, buffer };
}
async function open(w) {
    w.MaterialProjectStore.get.mockResolvedValue({ meta: { id: 'project-1', name: '示例桥' }, data: null });
    await w.steelTest.openProject('project-1');
}
it('preserves single/double scaling, unknown scope, zero and missing separately', async () => {
    const w = boot();
    const sheets = await w.steelTest.readXlsx((await workbook(w)).buffer);
    const parsed = w.steelTest.parseWorkbook(sheets);
    expect(w.steelTest.scaleModel(parsed, 'single').design.value).toBe(20);
    expect(w.steelTest.scaleModel(parsed, 'double').design.value).toBe(10);
    expect(w.steelTest.scaleModel(parsed, 'single').net.value).toBe(0);
    expect(w.steelTest.scaleModel(parsed, 'single').studs.values[0].value).toBe(8);
    expect(w.steelTest.detectScope(sheets, parsed, '示例.xlsx').mode).toBe('unknown');
    expect(w.steelTest.detectScope(sheets, parsed, '单幅双幅.xlsx').mode).toBe('unknown');
    const missing = w.steelTest.parseWorkbook(await w.steelTest.readXlsx((await workbook(w, '单幅.xlsx', null)).buffer));
    expect(missing.net.value).toBeNull();
    expect(missing.net.state).toBe('pending');
});
it('exports original workbook plus reviews and restores them without scaling twice', async () => {
    const w = boot(); await open(w);
    const file = await workbook(w);
    expect(await w.steelTest.importFile(file)).toBe(true);
    w.document.querySelector('[data-review="design"][data-checked="true"]').click();
    await vi.waitFor(() => expect(w.MaterialProjectStore.save).toHaveBeenCalledTimes(2));
    const rows = w.steelTest.exportRows();
    rows[0].note = '保留核对备注';
    const sheets = await w.steelTest.readXlsx(file.buffer);
    const parsed = w.steelTest.parseWorkbook(sheets);
    const scope = w.steelTest.detectScope(sheets, parsed, file.name);
    const bytes = await w.MaterialReviewXlsx.create({ buffer: file.buffer, filename: file.name, rows, scope: { version: 1, sourceScope: 'single', multiplier: 2, outputScope: 'double', headerSignature: scope.headerSignature } });
    const exported = { name: '示例单幅_单幅原表_含核对备注.xlsx', size: bytes.byteLength, arrayBuffer: async () => bytes.buffer };
    await vi.waitFor(() => expect(w.document.getElementById('upload-btn').disabled).toBe(false));
    expect(await w.steelTest.importFile(exported)).toBe(true);
    const restored = w.steelTest.exportRows();
    expect(restored.find(r => r.id === 'design')).toMatchObject({ value: 20, checked: true, note: '保留核对备注' });
});
it('failed saves retain edits, show retry, and prevent silently leaving the project', async () => {
    const w = boot(); await open(w);
    w.MaterialProjectStore.save.mockRejectedValue(Error('项目已被其他人更新'));
    expect(await w.steelTest.importFile(await workbook(w))).toBe(false);
    expect(w.document.getElementById('project-save-status').textContent).toContain('项目已被其他人更新');
    expect(w.document.getElementById('project-retry-save').hidden).toBe(false);
    await w.steelTest.showProjectHome();
    expect(w.document.getElementById('project-workspace').hidden).toBe(false);
    w.document.getElementById('project-reload').click();
    expect(w.confirm).toHaveBeenCalled();
    expect(w.MaterialProjectStore.get).toHaveBeenCalledTimes(1);
});
it('read-only users keep project selection and views while mutation and export controls disappear', async () => {
    const w = boot({ manage: false, export: false }); await open(w);
    expect(w.document.getElementById('new-project-btn').hidden).toBe(true);
    expect(w.document.getElementById('select-project-btn').hidden).toBe(false);
    expect(w.document.getElementById('drawing-upload-btn').hidden).toBe(true);
    expect(w.document.getElementById('drawing-export-all').hidden).toBe(true);
    expect(await w.steelTest.importFile(await workbook(w))).toBe(false);
    expect(w.MaterialProjectStore.save).not.toHaveBeenCalled();
});
it('restores server-parsed sheets and notes without fetching original files', async () => {
    const w = boot({ manage: false, export: false });
    const file = await workbook(w);
    const sheets = await w.steelTest.readXlsx(file.buffer);
    const serialized = sheets.map(({ name, cells, merges, maxRow, maxCol }) => ({ name, cells: [...cells.values()], merges, maxRow, maxCol }));
    w.MaterialProjectStore.get.mockResolvedValue({ meta: { id: 'project-1', name: '共享桥' }, data: { sheets: serialized, filename: file.name, reviews: [{ id: 'design', checked: true, signature: 'outdated', note: '上版备注' }], unit: 'kg' } });
    await w.steelTest.openProject('project-1');
    expect(w.document.getElementById('project-retry-save').hidden).toBe(true);
    expect(w.steelTest.exportRows().find(r => r.id === 'design')).toMatchObject({ value: 20, checked: false, note: '上版备注' });
    expect(w.document.querySelector('[data-review="design"]').disabled).toBe(true);
    w.document.querySelector('[data-unit="t"]').click();
    expect(w.document.querySelector('[data-review="design"]').disabled).toBe(true);
    expect(w.MaterialProjectStore.original).not.toHaveBeenCalled();
});

it('shows reload errors inside the visible workspace without discarding edits', async () => {
    const w = boot(); await open(w);
    w.MaterialProjectStore.get.mockRejectedValue(Error('服务器暂时不可用'));
    await w.steelTest.openProject('project-1');
    expect(w.document.getElementById('project-workspace').hidden).toBe(false);
    expect(w.document.getElementById('project-save-status').textContent).toContain('重新载入失败');
    expect(w.SteelGirderHasUnsavedChanges()).toBe(true);
});
