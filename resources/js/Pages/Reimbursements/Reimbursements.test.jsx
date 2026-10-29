// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import Show from './Show';
import Drafts from './Drafts';
import Draft from './Draft';
import Index from './Index';
import Workflow from './Workflow';
import { router } from '@inertiajs/react';
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({ children, ...props }) => <a {...props}>{children}</a>, router: { get: vi.fn() } }));
vi.mock('../../Components/Layout', () => ({ default: ({ children }) => <div>{children}</div> }));
const record = { id: 1, reimbursement_id: 1, number: 1, title: '客户拜访', type: '差旅交通', note: '出差', total_cents: 30, owner: { name: '业务员' }, status: 'pending', created_at: '2026-10-28T08:00:00Z', can_decide: true, node_index: 0, url: '/reimbursements/versions/1', zip_url: '/zip', versions: [{ number: 1, url: '/v1' }], flow: { nodes: [{ name: '经理审批', mode: 'any', people: [{ id: 2, name: '经理' }] }], cc: [] }, cc: [], decisions: [], items: [{ description: '交通', cents: 30, attachments: [{ id: 'a', name: '发票.pdf', mime: 'application/pdf', size: 100, preview_url: '/preview', download_url: '/download' }] }] };
const response = (data) => ({ ok: true, json: async () => data, blob: async () => new Blob(['pdf']) });
afterEach(() => { cleanup(); vi.unstubAllGlobals(); vi.clearAllMocks(); });
it('keeps information and actions expanded, only three sections collapsible and preview on demand', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => response({})));
    URL.createObjectURL = vi.fn(() => 'blob:preview'); URL.revokeObjectURL = vi.fn();
    const { container } = render(<Show record={record} />);
    expect(container.querySelectorAll('details')).toHaveLength(3);
    expect(screen.getByText('申请信息').closest('details')).toBeNull();
    expect(screen.getByText('审批处理').closest('details')).toBeNull();
    expect(screen.queryByText('审批记录')).toBeNull(); expect(screen.queryByText('演示审批人')).toBeNull();
    expect(screen.queryByRole('dialog')).toBeNull(); expect(fetch).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: '预览 发票.pdf' }));
    expect(await screen.findByRole('dialog')).toBeVisible();
    await waitFor(() => expect(container.querySelector('iframe')).not.toBeNull());
    fireEvent.click(screen.getByRole('button', { name: '关闭' }));
    expect(screen.queryByRole('dialog')).toBeNull(); expect(URL.revokeObjectURL).toHaveBeenCalled();
    expect(screen.getByRole('button', { name: '一键下载全部发票' })).toBeVisible();
});
it('requires rejection note, sends logged-in decision once and updates final status', async () => {
    let finish; vi.stubGlobal('fetch', vi.fn(() => new Promise((r) => { finish = r; })));
    render(<Show record={record} />);
    fireEvent.click(screen.getByRole('button', { name: '驳回' })); expect(fetch).not.toHaveBeenCalled();
    expect(screen.getByRole('status')).toHaveTextContent('请填写');
    fireEvent.click(screen.getByRole('button', { name: '同意' }));
    fireEvent.click(screen.getByRole('button', { name: '同意' })); expect(fetch).toHaveBeenCalledTimes(1);
    expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ node_index: 0, decision: 'approve', note: '' });
    finish(response({ record: { ...record, status: 'approved', node_index: 1, can_decide: false } }));
    expect(await screen.findByText('审批已通过，不代表已付款。')).toBeVisible();
    expect(screen.queryByRole('button', { name: '同意' })).toBeNull();
});
it('retains filters and hides configuration for unprivileged viewers', () => {
    render(<Index records={{ data: [], total: 0, current_page: 1 }} filters={{ scope: 'mine' }} capabilities={{ manage: false, configure: false }} />);
    expect(screen.queryByText('流程配置')).toBeNull(); expect(screen.getByText('报销通知')).toBeVisible();
    fireEvent.change(screen.getByLabelText('发起人'), { target: { value: '张三' } });
    fireEvent.change(screen.getByLabelText('状态'), { target: { value: 'pending' } });
    fireEvent.click(screen.getByRole('button', { name: '查询' }));
    expect(router.get).toHaveBeenCalledWith('/reimbursements', { scope: 'mine', owner: '张三', status: 'pending' });
});
it('workflow uses dropdown multiselect and preserves trigger association when sorting', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => response({ workflow: { revision: 1, definition: { nodes: [], cc: [], triggers: [] } } })));
    render(<Workflow users={[{ id: 1, name: '张经理' }, { id: 2, name: '李经理' }]} workflow={{ revision: 0, definition: { nodes: [{ name: '一审', mode: 'all', people: [1] }, { name: '二审', mode: 'any', people: [2] }], cc: [], triggers: ['node:0'] } }} />);
    expect(screen.getByRole('button', { name: '张经理' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: '张经理' }));
    expect(screen.getByPlaceholderText('搜索审批人')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: /完成/ }));
    fireEvent.click(screen.getAllByRole('button', { name: '下移' })[0]);
    fireEvent.click(screen.getByRole('button', { name: '保存流程' }));
    const payload = JSON.parse(fetch.mock.calls[0][1].body);
    expect(payload.nodes[0].name).toBe('二审'); expect(payload.triggers).toEqual(['node:1']);
    await screen.findByText('已保存，仅影响后续新提交版本。');
});
it('restores prototype list hierarchy without dropping scope, dates, pagination or real statuses', () => {
    const { container } = render(<Index records={{ data: [record], total: 21, current_page: 1, next_page_url: '/reimbursements?page=2' }} pending_count={21} filters={{ scope: 'review', from: '2026-10-01', to: '2026-10-31' }} capabilities={{ manage: false, configure: true }} />);
    expect(screen.getByText('每一笔费用，清晰有据')).toBeVisible();
    expect(screen.getByText('21 笔待审批')).toBeVisible();
    expect(screen.getByText('客户拜访').tagName).toBe('STRONG');
    expect(screen.getByText('¥0.30').tagName).toBe('STRONG');
    expect(container.querySelector('.oa-avatar')).toHaveTextContent('业');
    const panel = screen.getByRole('region', { name: '报销申请列表' });
    expect(panel).toContainElement(screen.getByRole('table'));
    expect(panel).toContainElement(screen.getByLabelText('发起人'));
    expect(screen.getByRole('link', { name: '下一页' })).toHaveAttribute('href', '/reimbursements?page=2');
    expect(screen.queryByRole('option', { name: '全部报销' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: '已通过' }));
    expect(router.get).toHaveBeenLastCalledWith('/reimbursements', { scope: 'review', from: '2026-10-01', to: '2026-10-31', status: 'approved' });
    fireEvent.click(screen.getByRole('button', { name: '重置' }));
    expect(router.get).toHaveBeenLastCalledWith('/reimbursements', { scope: 'review' });
});

it('displays invoice dates and preserves legacy expense details and approval controls', () => {
    const dated = {...record, items: [{...record.items[0], invoice_date: '2026-10-29'}, {...record.items[0], description: '旧费用', attachments: []}]};
    render(<Show record={dated} />);
    expect(screen.getByText('开票日期：2026-10-29')).toBeVisible();
    expect(screen.getByText('开票日期：未填写')).toBeVisible();
    expect(screen.getByRole('button', {name: '预览 发票.pdf'})).toBeVisible();
    expect(screen.getByRole('button', {name: '同意'})).toBeVisible();
});

it('draft list and read-only detail preserve incomplete fields without approval controls', () => {
    const draft = { id: 'draft', url: '/reimbursements/drafts/draft', updated_at: '2026-10-29T08:00:00Z', payload: { title: '', type: '其他', note: '未完成说明', cc: [], items: [{ amount: '1.', description: '手工说明\n发票号码：12345678', invoice_date: '', attachments: [{ id: 'pdf', name: '发票.pdf', url: '/private-file' }] }] } };
    const { unmount } = render(<Drafts records={{ data: [draft], links: [] }} />);
    expect(screen.getByText('未命名报销')).toBeVisible();
    expect(screen.getByRole('link', { name: '查看草稿' })).toHaveAttribute('href', draft.url);
    unmount(); render(<Draft draft={draft} />);
    expect(screen.getByText('金额：1.')).toBeVisible();
    expect(screen.getByText('开票日期：未填写')).toBeVisible();
    expect(screen.getByRole('link', { name: '发票.pdf' })).toHaveAttribute('href', '/private-file');
    expect(screen.queryByRole('button', { name: /审批|提交/ })).toBeNull();
});
