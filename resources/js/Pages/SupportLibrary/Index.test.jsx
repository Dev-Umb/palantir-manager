// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import Index from './Index';
import db from '../../../support-library/baseline.json';
import fixtures from '../../../../tests/Fixtures/support-library/source-results.json';

vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({ children, ...props }) => <a {...props}>{children}</a>, usePage: () => ({ url: '/support-library', props: { nav: [] } }), router: { on: () => () => {} } }));
vi.mock('../../Components/Layout', () => ({ default: ({ children, hideHeader }) => <div>{!hideHeader && <header>平台顶部标题</header>}{children}</div> }));
const urls = Object.fromEntries(['library', 'save', 'query', 'imports.preview', 'imports.commit', 'exports.result', 'exports.library', 'exports.html', 'template'].map((key) => [key, '/'+key]));
const initial = () => ({ revision: 1, library: structuredClone(db), source_filename: '支架库.xlsx', updated_at: '2026-10-13T01:00:00Z' });
const response = (data, status = 200) => ({ ok: status >= 200 && status < 300, status, redirected: false, json: async () => data, blob: async () => new Blob(['xlsx']) });
function configure() {
    const section = screen.getByRole('region', { name: '搭配1' });
    fireEvent.change(within(section).getByLabelText('支架用途'), { target: { value: '吊装' } });
    fireEvent.change(within(section).getByLabelText('柱撑型号'), { target: { value: 'C14A' } });
    fireEvent.change(within(section).getByLabelText('钢柱规格'), { target: { value: '273' } });
    fireEvent.click(screen.getByRole('button', { name: '6米 / 横向2米 / 纵向3米 / 4组' }));
}
function mount(capabilities = { manage: true, export: true }) { return render(<Index initial={initial()} urls={urls} capabilities={capabilities} />); }
beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn(async (url, options) => {
        if (url === urls.query) {
            const input = JSON.parse(options.body);
            return response({ library_revision: input.library_revision, request_fingerprint: input.request_fingerprint, result: fixtures[input.entries[0].expression] });
        }
        return response(initial());
    }));
});
afterEach(() => { cleanup(); vi.unstubAllGlobals(); vi.restoreAllMocks(); });

it('preserves demo regions buttons defaults examples and all table columns', () => {
    mount();
    expect(screen.getByLabelText('支架用途')).toHaveValue('');
    expect(screen.getByLabelText('钢柱规格')).toHaveValue('');
    expect(screen.getByLabelText(/支架组合或单柱/)).toHaveValue('6+5@2+3@3+3/4');
    expect(screen.getByRole('button', { name: /导出明细/ })).toBeDisabled();
    for (const name of ['从Excel导入支架库', '导出当前数据库 Excel', '修改当前数据库', '下载更新版HTML', '下载Excel格式模板', '＋ 添加搭配', '6米单柱 / 4组', '6+5米叠接单柱 / 4组']) expect(screen.getByRole('button', { name })).toBeVisible();
    expect(screen.getByRole('heading', { name: '本次包含范围' })).toBeVisible();
    expect(screen.getAllByRole('columnheader')).toHaveLength(6);
});
it('hides denied actions while retaining query catalog and scope notice', () => {
    mount({ manage: false, export: false });
    for (const name of ['从Excel导入支架库', '导出当前数据库 Excel', '修改当前数据库', '下载更新版HTML', '下载Excel格式模板']) expect(screen.queryByRole('button', { name })).toBeNull();
    expect(screen.getByRole('button', { name: /检索全部搭配/ })).toBeVisible();
    expect(screen.getByText(/0.44 kg\/套估算/)).toBeVisible();
});
it('single-column model is disabled and omitted, switching back restores model requirement', async () => {
    mount(); configure();
    fireEvent.click(screen.getByRole('button', { name: '6+5米叠接单柱 / 4组' }));
    expect(screen.getByLabelText('柱撑型号（单柱无需选择）')).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: /检索全部搭配/ }));
    await screen.findByText('3247.3 kg');
    const input = JSON.parse(fetch.mock.calls.find(([url]) => url === urls.query)[1].body);
    expect(input.entries[0].criteria.column_brace_model).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: '6米 / 横向2米 / 纵向3米 / 4组' }));
    expect(screen.getByLabelText('柱撑型号')).toBeEnabled();
    expect(screen.getByRole('button', { name: /导出明细/ })).toBeDisabled();
});
it('blank appended entry blocks batch and deletion renumbers while preserving first input', async () => {
    mount(); configure();
    fireEvent.click(screen.getByRole('button', { name: '＋ 添加搭配' }));
    const second = screen.getByRole('region', { name: '搭配2' });
    expect(within(second).getByLabelText('支架用途')).toHaveValue('吊装');
    expect(within(second).getByLabelText(/支架组合或单柱/)).toHaveFocus();
    fireEvent.click(screen.getByRole('button', { name: /检索全部搭配/ }));
    await screen.findByText(/搭配2：请填写组合/);
    expect(fetch).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: '删除此搭配' }));
    expect(screen.queryByRole('region', { name: '搭配2' })).toBeNull();
    expect(screen.getByLabelText(/支架组合或单柱/)).toHaveValue('6@2@3/4');
});
it('complete and partial results retain quantities and distinct labels then invalidate on input changes', async () => {
    mount(); configure();
    fireEvent.click(screen.getByRole('button', { name: /检索全部搭配/ }));
    await screen.findByText('10012.9 kg');
    expect(screen.getAllByRole('columnheader')).toHaveLength(17); // eight result columns, three subtotals and six catalog columns
    expect(screen.getByRole('button', { name: /导出明细/ })).toBeEnabled();
    fireEvent.change(screen.getByLabelText(/支架组合或单柱/), { target: { value: '6+5+0.7@2+3@3+3/4' } });
    expect(screen.queryByText('10012.9 kg')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /检索全部搭配/ }));
    await screen.findByText('52255.9 kg');
    expect(screen.getByText('已计含螺栓估算重')).toBeVisible();
    expect(screen.getByText('已匹配柱撑（未算齐）')).toBeVisible();
    expect(screen.getByText('已计小计')).toBeVisible();
});
it('late query responses cannot overwrite newly changed inputs or re-enable exports', async () => {
    let finish;
    fetch.mockImplementationOnce((url, options) => new Promise((resolve) => { finish = () => { const input = JSON.parse(options.body); resolve(response({ library_revision: 1, request_fingerprint: input.request_fingerprint, result: fixtures['6@2@3/4'] })); }; }));
    mount(); configure();
    fireEvent.click(screen.getByRole('button', { name: /检索全部搭配/ }));
    fireEvent.change(screen.getByLabelText(/支架组合或单柱/), { target: { value: '6/4' } });
    await act(async () => finish());
    expect(screen.queryByText('10012.9 kg')).toBeNull();
    expect(screen.getByRole('button', { name: /导出明细/ })).toBeDisabled();
});
it('editor drafts do not mutate shared query, cancellation discards them and conflict preserves input', async () => {
    mount(); configure();
    fireEvent.click(screen.getByRole('button', { name: '修改当前数据库' }));
    fireEvent.change(screen.getByLabelText('单重kg（空＝未知） 第1行'), { target: { value: '500' } });
    fireEvent.click(screen.getByRole('button', { name: /检索全部搭配/ }));
    await screen.findByText('10012.9 kg');
    fetch.mockImplementationOnce(async () => response({ message: '共享库已被他人更新', code: 'REVISION_CONFLICT' }, 409));
    fireEvent.click(screen.getByRole('button', { name: '保存并更新检索' }));
    await screen.findByText(/未确认保存：共享库已被他人更新/);
    expect(screen.getByLabelText('单重kg（空＝未知） 第1行')).toHaveValue(500);
    fireEvent.click(screen.getByRole('button', { name: '取消', exact: true }));
    expect(screen.queryByLabelText('单重kg（空＝未知） 第1行')).toBeNull();
});
it('successful save and failed recalculate are shown as distinct states', async () => {
    mount(); configure();
    fireEvent.click(screen.getByRole('button', { name: '修改当前数据库' }));
    fetch.mockImplementation(async (url) => url === urls.save ? response({ ...initial(), revision: 2 }) : response({ message: '本次查询失败' }, 500));
    fireEvent.click(screen.getByRole('button', { name: '保存并更新检索' }));
    await screen.findByText('共享库已保存，版本2。');
    await screen.findByText('本次查询失败');
    expect(screen.queryByRole('region', { name: '修改当前数据库' })).toBeNull();
});
it('catalog uses only first criteria and model changes do not change directory', () => {
    mount(); configure();
    const initialRows = screen.getAllByRole('row').length;
    fireEvent.change(screen.getByLabelText('柱撑型号'), { target: { value: 'C16A' } });
    expect(screen.getAllByRole('row')).toHaveLength(initialRows);
    fireEvent.click(screen.getByRole('button', { name: '＋ 添加搭配' }));
    const second = screen.getByRole('region', { name: '搭配2' });
    fireEvent.change(within(second).getByLabelText('钢柱规格'), { target: { value: '530' } });
    expect(screen.getAllByRole('row')).toHaveLength(initialRows);
});
it('preview and cancel do not change library and shows null overwrite warning', async () => {
    mount();
    fetch.mockImplementationOnce(async () => response({ preview_token: 'token', filename: '库.xlsx', stats: { partsAdded: 0, partsUpdated: 52, framesAdded: 0, framesUpdated: 30 }, compositionCount: 58, warnings: [] }));
    fireEvent.change(screen.getByLabelText('选择Excel支架库'), { target: { files: [new File(['bytes'], '库.xlsx')] } });
    await screen.findByText(/空值也会覆盖/);
    expect(screen.getByRole('button', { name: '确认导入并更新' })).toBeEnabled();
    expect(fetch).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole('button', { name: '取消', exact: true }));
    expect(screen.queryByRole('region', { name: 'Excel支架库导入' })).toBeNull();
});
it('database export refuses unsaved drafts and preserves result export permission gate', async () => {
    mount();
    fireEvent.click(screen.getByRole('button', { name: '修改当前数据库' }));
    fireEvent.click(screen.getByRole('button', { name: '导出当前数据库 Excel' }));
    expect(screen.getByText('请先保存或取消正在编辑的数据库，再导出当前库。')).toBeVisible();
    expect(fetch).not.toHaveBeenCalled();
});

it('shows one demo title and suppresses the unrelated platform header', () => {
    mount();
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1, name: '支架检索' })).toBeVisible();
    expect(screen.queryByText('平台顶部标题')).toBeNull();
    expect(screen.getByRole('navigation', { name: '支架库页面导航' })).toBeVisible();
    expect(screen.getByRole('heading', { name: '输入支架组合' })).toBeVisible();
});

it('blocks incomplete criteria like the demo and permits a column query without a brace model', async () => {
    mount();
    fireEvent.click(screen.getByRole('button', { name: /检索全部搭配/ }));
    expect(fetch).not.toHaveBeenCalled();
    expect(screen.getByLabelText('支架用途')).toBeInvalid();
    fireEvent.change(screen.getByLabelText('支架用途'), { target: { value: '吊装' } });
    fireEvent.change(screen.getByLabelText('钢柱规格'), { target: { value: '273' } });
    fireEvent.click(screen.getByRole('button', { name: /检索全部搭配/ }));
    expect(fetch).not.toHaveBeenCalled();
    expect(screen.getByLabelText('柱撑型号')).toBeInvalid();
    fireEvent.click(screen.getByRole('button', { name: '6+5米叠接单柱 / 4组' }));
    fireEvent.click(screen.getByRole('button', { name: /检索全部搭配/ }));
    await screen.findByText('3247.3 kg');
    expect(fetch).toHaveBeenCalledTimes(1);
});
it('keeps all editor actions before the scrollable records and preserves section switching and cancellation', () => {
    mount();
    fireEvent.click(screen.getByRole('button', { name: '修改当前数据库' }));
    const toolbar = screen.getByRole('group', { name: '支架库编辑操作' });
    const table = screen.getByRole('region', { name: '支架库编辑表' });
    expect(toolbar.compareDocumentPosition(table) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(within(table).getAllByRole('row')).toHaveLength(53);
    fireEvent.change(within(toolbar).getByLabelText('编辑区段'), { target: { value: 'frames' } });
    expect(within(table).getAllByRole('row')).toHaveLength(31);
    fireEvent.change(within(toolbar).getByLabelText('编辑区段'), { target: { value: 'composition' } });
    expect(within(table).getAllByRole('row')).toHaveLength(59);
    fireEvent.click(within(toolbar).getByRole('button', { name: '添加一行' }));
    expect(within(table).getAllByRole('row')).toHaveLength(60);
    expect(within(toolbar).getByRole('button', { name: '保存并更新检索' })).toBeEnabled();
    fireEvent.click(within(toolbar).getByRole('button', { name: '取消', exact: true }));
    expect(screen.queryByRole('region', { name: '修改当前数据库' })).toBeNull();
    expect(fetch).not.toHaveBeenCalled();
});
