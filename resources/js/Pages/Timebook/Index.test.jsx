// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Index, { localDate } from './Index';

const auth = vi.hoisted(() => ({ permissions: [] }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, usePage: () => ({ props: { auth } }) }));
vi.mock('../../Components/Layout', () => ({ default: ({ children }) => <main>{children}</main> }));
const urls = { names: '/timebook/names', records: '/timebook/records', entries: '/timebook/entries', export: '/timebook/export.xlsx' };
const row = { id: 1, worker_id: 7, name: '张三', day: '2026-09-18', days: 1, overtime: 0, project: '焊接', note: '', version: 1 };
const initial = () => ({ records: [row], summary: [{ ...row, count: 1, first_day: row.day, last_day: row.day }],
    totals: { count: 1, people: 1, days: 1, overtime: 0 }, filters: { start: '2026-09-01', end: '2026-09-30', q: '', worker: '' },
    pagination: { page: 1, last_page: 1, total: 1 } });
const response = (data, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => data });
const calls = (method) => fetch.mock.calls.filter(([, options]) => options?.method === method);

beforeEach(() => {
    auth.permissions = ['view', 'create', 'update', 'delete', 'export', 'audit'].map((key) => `timebook.${key}`);
    vi.stubGlobal('fetch', vi.fn(async (url) => response(url.startsWith(urls.names) ? [] : initial())));
});
afterEach(() => { cleanup(); vi.unstubAllGlobals(); vi.restoreAllMocks(); });

describe('工日簿独立页面', () => {
    it('preserves every field and action label when mobile CSS presents rows as cards', () => {
        const { container } = render(<Index initial={initial()} urls={urls} />);
        expect([...container.querySelectorAll('tbody td[data-label]')].map((cell) => cell.dataset.label))
            .toEqual(['日期', '姓名', '工日（天）', '加班（小时）', '工作项目', '备注', '操作']);
        expect(screen.getByRole('button', { name: '修改', exact: true })).toBeVisible();
        expect(screen.getByRole('button', { name: '留痕' })).toBeVisible();
        expect(screen.getByRole('button', { name: '删除', exact: true })).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: '人员汇总' }));
        expect([...container.querySelectorAll('tbody td[data-label]')].map((cell) => cell.dataset.label))
            .toEqual(['姓名', '累计工日（天）', '加班（小时）', '记录数', '首次日期', '最近日期']);
    });

    it('keeps read queries visible but hides unauthorized write export and audit controls', () => {
        auth.permissions = ['timebook.view'];
        render(<Index initial={initial()} urls={urls} />);
        expect(screen.getByRole('button', { name: '查询' })).toBeVisible();
        expect(screen.getByRole('button', { name: '人员汇总' })).toBeVisible();
        for (const name of ['保存记工', '修改', '删除', '留痕', '导出 Excel']) expect(screen.queryByRole('button', { name })).not.toBeInTheDocument();
        expect(screen.queryByLabelText('姓名')).not.toBeInTheDocument();
    });

    it('preserves continuous-entry date and project and distinguishes successful save from failed refresh', async () => {
        fetch.mockImplementation(async (url, options) => {
            if (options.method === 'POST') return response(row, 201);
            if (url.startsWith(urls.names)) return response([]);
            return response({ message: '查询刷新失败' }, 500);
        });
        render(<Index initial={initial()} urls={urls} />);
        fireEvent.change(screen.getByLabelText('工作日期'), { target: { value: '2026-09-17' } });
        fireEvent.change(screen.getByLabelText('姓名'), { target: { value: '李四' } });
        fireEvent.change(screen.getByLabelText('工作项目'), { target: { value: '总装' } });
        fireEvent.change(screen.getByLabelText('备注'), { target: { value: '测试' } });
        fireEvent.click(screen.getByRole('button', { name: '保存记工' }));
        await screen.findByText('记工已保存。');
        await screen.findByText('查询刷新失败');
        expect(screen.getByLabelText('姓名')).toHaveValue('');
        expect(screen.getByLabelText('备注')).toHaveValue('');
        expect(screen.getByLabelText('工作日期')).toHaveValue('2026-09-17');
        expect(screen.getByLabelText('工作项目')).toHaveValue('总装');
        expect(screen.getByRole('button', { name: '导出 Excel' })).toBeDisabled();
        expect(calls('POST')).toHaveLength(1);
    });

    it('retains failed input and opens duplicates with the returned version', async () => {
        fetch.mockImplementation(async (url, options) => options.method === 'POST'
            ? response({ message: '已存在', existing: { ...row, version: 4 } }, 409) : response(url.startsWith(urls.names) ? [] : initial()));
        render(<Index initial={initial()} urls={urls} />);
        fireEvent.change(screen.getByLabelText('姓名'), { target: { value: '张三' } });
        fireEvent.click(screen.getByRole('button', { name: '保存记工' }));
        await screen.findByText('已存在');
        expect(screen.getByLabelText('姓名')).toHaveValue('张三');
        fireEvent.click(screen.getByRole('button', { name: '打开已有记录修改' }));
        expect(screen.getByText('修改姓名只改变这一条记工的归属，不会为该人员全部历史更名。')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: '保存修改' }));
        await waitFor(() => expect(calls('PUT')).toHaveLength(1));
        expect(JSON.parse(calls('PUT')[0][1].body).version).toBe(4);
    });

    it('keeps previous results after a failed query and retains current applied export filter', async () => {
        fetch.mockImplementation(async (url) => url.startsWith(urls.names) ? response([]) : response({ message: '日期范围错误' }, 422));
        render(<Index initial={initial()} urls={urls} />);
        fireEvent.change(screen.getByLabelText('开始日期'), { target: { value: '2026-10-01' } });
        expect(screen.getByText(/筛选条件尚未应用/)).toBeVisible();
        expect(screen.getByText(/生效范围：2026-09-01/)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: '查询', exact: true }));
        await screen.findByText('日期范围错误');
        expect(screen.getByRole('button', { name: '张三', exact: true })).toBeVisible();
        expect(screen.getByRole('button', { name: '导出 Excel' })).toBeDisabled();
    });

    it('queries exact person with current dates and clears all history restrictions', async () => {
        render(<Index initial={initial()} urls={urls} />);
        fireEvent.click(screen.getByRole('button', { name: '张三', exact: true }));
        await waitFor(() => expect(fetch).toHaveBeenCalledWith(expect.stringContaining('worker=7'), expect.anything()));
        expect(fetch.mock.calls[0][0]).toContain('start=2026-09-01');
        await waitFor(() => expect(screen.getByRole('button', { name: '全部历史' })).toBeEnabled());
        fireEvent.click(screen.getByRole('button', { name: '全部历史' }));
        await waitFor(() => expect(fetch).toHaveBeenCalledWith(`${urls.records}?start=&end=&q=&worker=&page=1`, expect.anything()));
    });

    it('confirms deletion and restores using the post-delete version', async () => {
        fetch.mockImplementation(async (url, options) => {
            if (options.method === 'DELETE') return response({ ...row, version: 2, deleted: 1 });
            if (options.method === 'POST') return response({ ...row, version: 3, deleted: 0 });
            return response(url.startsWith(urls.names) ? [] : initial());
        });
        render(<Index initial={initial()} urls={urls} />);
        fireEvent.click(screen.getByRole('button', { name: '删除', exact: true }));
        expect(calls('DELETE')).toHaveLength(0);
        expect(screen.getByRole('dialog')).toHaveTextContent('张三');
        fireEvent.click(screen.getByRole('button', { name: '确认删除' }));
        const undo = await screen.findByRole('button', { name: /撤销删除/ });
        await waitFor(() => expect(undo).toBeEnabled());
        fireEvent.click(undo);
        await screen.findByText('记工已恢复。');
        expect(JSON.parse(calls('POST')[0][1].body)).toEqual({ version: 2 });
    });

    it('does not allow older asynchronous query results to overwrite a newer selection', async () => {
        let resolveFirst;
        fetch.mockImplementation((url) => url.includes('worker=7')
            ? new Promise((resolve) => { resolveFirst = resolve; }) : Promise.resolve(response({ ...initial(), totals: { count: 2, people: 2, days: 2, overtime: 0 } })));
        render(<Index initial={initial()} urls={urls} />);
        fireEvent.click(screen.getByRole('button', { name: '张三', exact: true }));
        fireEvent.click(screen.getByRole('button', { name: '清除人员筛选' }));
        await waitFor(() => expect(screen.getByLabelText('当前筛选统计')).toHaveTextContent('2'));
        await act(async () => resolveFirst(response(initial())));
        expect(screen.getByLabelText('当前筛选统计')).toHaveTextContent('2');
    });

    it('shows expired-session guidance without clearing unsaved fields', async () => {
        fetch.mockResolvedValue(response({}, 419));
        render(<Index initial={initial()} urls={urls} />);
        fireEvent.change(screen.getByLabelText('姓名'), { target: { value: '尚未保存' } });
        fireEvent.click(screen.getByRole('button', { name: '保存记工' }));
        await screen.findByRole('link', { name: '打开登录页' });
        expect(screen.getByLabelText('姓名')).toHaveValue('尚未保存');
    });

    it('uses the local business day instead of a UTC string', () => {
        expect(localDate(new Date(2026, 8, 18, 0, 1))).toBe('2026-09-18');
    });
});
