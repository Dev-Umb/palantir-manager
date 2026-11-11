// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import Form, { amountCents } from './Form';
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({ children, ...props }) => <a {...props}>{children}</a>, router: { visit: vi.fn(), on: vi.fn() } }));
vi.mock('../../Components/Layout', () => ({ default: ({ children }) => <div>{children}</div> }));
const response = data => ({ ok: true, json: async () => data });
const failure = (status, message) => ({ ok: false, status, json: async () => ({ message }) });
const file = { id: 'file-1', name: '住宿.pdf', url: '/private/file', mime: 'application/pdf' };
const payload = { title: '出差报销', type: '住宿费', note: '拜访客户', cc: [], items: [{ description: '住宿费', amount: '100.10', invoice_date: '', attachments: [file] }] };
const draft = { id: 'draft-1', revision: 2, payload };
const props = { types: ['住宿费', '其他'], users: [{ id: 12, name: '知会人' }] };
function fill() {
    fireEvent.change(screen.getByLabelText('报销标题'), { target: { value: '出差报销' } });
    fireEvent.change(screen.getByLabelText('报销说明'), { target: { value: '拜访客户' } });
    fireEvent.change(screen.getByLabelText('费用说明 1'), { target: { value: '住宿费' } });
    fireEvent.change(screen.getByLabelText('金额 1（元）'), { target: { value: '100.10' } });
}
afterEach(() => { cleanup(); vi.unstubAllGlobals(); vi.clearAllMocks(); });
it('computes money in integer cents and rejects invalid or zero values', () => {
    expect(amountCents('100.10')).toBe(10010); expect(amountCents('0.01')).toBe(1);
    for (const value of ['0', '-1', '1e3', '0.001', '01', '10000000', '']) expect(amountCents(value)).toBeNull();
});
it('creates an incomplete private manual draft without invoking AI', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url, options) => {
        const body = JSON.parse(options.body);
        return response({ draft: { id: body.id, revision: 1, payload: body.payload } });
    }));
    render(<Form {...props} />); fill();
    fireEvent.click(screen.getByRole('button', { name: '保存草稿' }));
    await screen.findByText('草稿已保存，可在小程序继续编辑');
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch.mock.calls[0][0]).toBe('/reimbursements/drafts');
    expect(JSON.parse(fetch.mock.calls[0][1].body).payload.items[0].attachments).toEqual([]);
    expect(screen.getByText('合计 ¥100.10')).toBeVisible();
});
it('uploads an attachment into the same expense with its draft context and no recognition', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url, options) => {
        const context = JSON.parse(options.body.get('draft_context'));
        return response({ draft: { id: context.id, revision: 2, payload: { ...context.payload, items: [{ ...context.payload.items[0], attachments: [file] }] } } });
    }));
    render(<Form {...props} />); fill();
    fireEvent.change(screen.getByLabelText('上传附件 1'), { target: { files: [new File(['pdf'], '住宿.pdf', { type: 'application/pdf' })] } });
    await screen.findByRole('link', { name: '住宿.pdf' });
    expect(fetch).toHaveBeenCalledTimes(1); expect(fetch.mock.calls[0][0]).toBe('/reimbursements/uploads');
    expect(screen.getAllByRole('heading', { name: /^费用 \d/ })).toHaveLength(1);
    expect(screen.getByLabelText('费用说明 1')).toHaveValue('住宿费');
});
it('retries an uncertain upload with the same operation and preserves entered content', async () => {
    let calls = 0;
    vi.stubGlobal('fetch', vi.fn(async (url, options) => {
        if (++calls === 1) throw Error('网络中断');
        const context = JSON.parse(options.body.get('draft_context'));
        return response({ draft: { id: context.id, revision: 2, payload: { ...context.payload, items: [{ ...context.payload.items[0], attachments: [file] }] } } });
    }));
    render(<Form {...props} />); fill();
    fireEvent.change(screen.getByLabelText('上传附件 1'), { target: { files: [new File(['pdf'], '住宿.pdf')] } });
    await screen.findByText('住宿.pdf 上传未完成');
    expect(screen.getByLabelText('报销标题')).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: '重试上传' }));
    await screen.findByRole('link', { name: '住宿.pdf' });
    expect(fetch.mock.calls[0][1].body.get('draft_context')).toBe(fetch.mock.calls[1][1].body.get('draft_context'));
});
it('submits an existing draft and retries the exact payload after lost response', async () => {
    let calls = 0;
    vi.stubGlobal('fetch', vi.fn(async () => { if (++calls === 1) throw Error('网络中断'); return response({ record: { url: '/reimbursements/versions/100' } }); }));
    render(<Form {...props} draft={draft} />);
    fireEvent.click(screen.getByRole('button', { name: '提交申请' }));
    await screen.findByRole('button', { name: '重试提交' });
    await waitFor(() => expect(screen.getByRole('button', { name: '重试提交' })).toBeEnabled());
    expect(screen.getByLabelText('费用说明 1')).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: '重试提交' }));
    await waitFor(() => expect(router.visit).toHaveBeenCalledWith('/reimbursements/versions/100'));
    expect(fetch.mock.calls[0][0]).toBe('/reimbursements');
    expect(fetch.mock.calls[0][1].body).toBe(fetch.mock.calls[1][1].body);
    const body = JSON.parse(fetch.mock.calls[0][1].body);
    expect(body.draft_revision).toBe(2); expect(body.items[0].attachments).toEqual(['file-1']);
});
it('preserves input on draft conflict and does not submit a stale version', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => failure(409, '另一端已修改草稿')));
    render(<Form {...props} draft={draft} />);
    fireEvent.change(screen.getByLabelText('报销说明'), { target: { value: 'PC新内容' } });
    fireEvent.click(screen.getByRole('button', { name: '提交申请' }));
    await screen.findByText('另一端已修改草稿');
    expect(screen.getByLabelText('报销说明')).toHaveValue('PC新内容');
    expect(screen.getByRole('button', { name: '提交申请' })).toBeDisabled();
    expect(fetch).toHaveBeenCalledTimes(1); expect(fetch.mock.calls[0][0]).toBe('/reimbursements/drafts/draft-1');
});
it('retains historical long descriptions until edited and caps new description at twenty characters', () => {
    vi.stubGlobal('fetch', vi.fn());
    const description = '这是一条原始费用说明需要用户确认后才能进行缩短处理';
    render(<Form {...props} source={{ ...payload, id: 9, reimbursement_id: 9, number: 1, items: [{ ...payload.items[0], description }] }} />);
    expect(screen.getByLabelText('费用说明 1')).toHaveValue(description);
    expect(screen.getByText(/原费用说明超过20字/)).toBeVisible();
    fireEvent.change(screen.getByLabelText('费用说明 1'), { target: { value: description + '修改' } });
    expect(Array.from(screen.getByLabelText('费用说明 1').value)).toHaveLength(20);
    expect(fetch).not.toHaveBeenCalled();
});


it('continues incomplete drafts whose nullable fields are normalized by the server', () => {
    render(<Form {...props} draft={{ ...draft, payload: { ...payload, title: null, note: null, items: [{ description: null, amount: null, invoice_date: null, attachments: [] }] } }} />);
    expect(screen.getByLabelText('费用说明 1')).toHaveValue('');
    expect(screen.getByLabelText('金额 1（元）')).toHaveValue('');
    expect(screen.getByText('合计 ¥0.00')).toBeVisible();
    expect(screen.getByRole('button', { name: '保存草稿' })).toBeEnabled();
});


it('preserves evidence links for retained files and clears only a removed source reference', async () => {
    const other = { ...file, id: 'other', name: '补充附件.pdf' };
    vi.stubGlobal('fetch', vi.fn(async (url, options) => {
        const body = JSON.parse(options.body);
        return response({ draft: { ...draft, revision: body.revision + 1, payload: { ...body.payload, items: body.payload.items.map(item => ({ ...item, attachments: item.attachments.map(id => [file, other].find(value => value.id === id)) })) } } });
    }));
    render(<Form {...props} draft={{ ...draft, payload: { ...payload, items: [{ ...payload.items[0], attachments: [file, other], evidence_source: { upload_id: file.id, candidate_id: '1-1' } }] } }} />);
    fireEvent.click(screen.getByRole('button', { name: '移除 补充附件.pdf' }));
    fireEvent.click(screen.getByRole('button', { name: '保存草稿' }));
    await screen.findByText('草稿已保存，可在小程序继续编辑');
    expect(JSON.parse(fetch.mock.calls[0][1].body).payload.items[0].evidence_source).toEqual({ upload_id: file.id, candidate_id: '1-1' });
    fireEvent.click(screen.getByRole('button', { name: '移除 住宿.pdf' }));
    fireEvent.click(screen.getByRole('button', { name: '保存草稿' }));
    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
    expect(JSON.parse(fetch.mock.calls[1][1].body).payload.items[0]).not.toHaveProperty('evidence_source');
});
