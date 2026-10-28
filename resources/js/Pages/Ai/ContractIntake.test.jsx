// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ContractIntake from './ContractIntake';

beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ intakes: [] }) }));
});
afterEach(() => { cleanup(); vi.unstubAllGlobals(); });

async function chooseFile(size) {
    render(<ContractIntake onClose={() => {}} />);
    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
    const file = new File(['%PDF-1.4'], '合同.pdf', { type: 'application/pdf' });
    Object.defineProperty(file, 'size', { value: size });
    fireEvent.change(screen.getByLabelText('合同文件'), { target: { files: [file] } });
    fireEvent.click(screen.getByRole('button', { name: '上传并识别' }));
}

describe('contract upload size and errors', () => {
    it('accepts a 60 MB file and preserves asynchronous intake status', async () => {
        fetch.mockResolvedValueOnce({ ok: true, json: async () => ({ intakes: [] }) })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ id: 'intake', status: 'staged', file_name: '合同.pdf' }) });
        await chooseFile(60 * 1024 * 1024);
        await screen.findByText('正在识别 合同.pdf');
        expect(fetch.mock.calls[1][1].body).toBeInstanceOf(FormData);
    });
    it('rejects more than 100 MB before sending and retains the file for correction', async () => {
        await chooseFile(100 * 1024 * 1024 + 1);
        expect(screen.getByRole('alert').textContent).toContain('不能超过 100 MB');
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(screen.getByLabelText('合同文件').files[0].name).toBe('合同.pdf');
    });
    it('handles an HTML 413 without trying to decode JSON and allows retry', async () => {
        const json = vi.fn().mockRejectedValue(new Error('HTML'));
        fetch.mockResolvedValueOnce({ ok: true, json: async () => ({ intakes: [] }) })
            .mockResolvedValueOnce({ ok: false, status: 413, json });
        await chooseFile(9 * 1024 * 1024);
        await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('上传请求过大'));
        expect(json).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: '上传并识别' }).disabled).toBe(false);
    });
    it('keeps backend validation messages visible', async () => {
        fetch.mockResolvedValueOnce({ ok: true, json: async () => ({ intakes: [] }) })
            .mockResolvedValueOnce({ ok: false, status: 422, json: async () => ({ errors: { file: ['只支持有效合同文件'] } }) });
        await chooseFile(1024);
        await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('只支持有效合同文件'));
    });
});

describe('explicit project confirmation', () => {
    it('requires a preview and confirm click before importing into the selected project', async () => {
        const intake = { id: 'intake-review', status: 'review', file_name: '待核对合同.pdf', extraction: { amount: 12000, warnings: [], evidence: [] } };
        fetch.mockImplementation(async (url, options = {}) => {
            const data = url === '/ai/contracts' ? { intakes: [intake] }
                : url.startsWith('/ai/contracts/projects?') ? { projects: [{ id: 'project-a', code: 'PRJ-A', name: '房武2标' }], truncated: false }
                    : url === '/ai/contracts/projects/project-a' ? { id: 'project-a', contracts: [], contract_amount: 80000, can_update_project_amount: false }
                        : url.endsWith('/preview') ? { token: 'review-token', project: { code: 'PRJ-A', name: '房武2标' }, contract: '新建合同', file: '待核对合同.pdf', changes: [], effects: ['历史财务金额不修改'] }
                            : url.endsWith('/confirm') ? { project_id: 'project-a', contract_code: 'CON-A', message: '合同已归档' } : {};
            return { ok: true, json: async () => data };
        });
        render(<ContractIntake onClose={() => {}} />);
        fireEvent.click(await screen.findByRole('button', { name: /待核对合同.pdf/ }));
        await screen.findByRole('option', { name: 'PRJ-A · 房武2标' });
        fireEvent.change(screen.getByLabelText('归档项目'), { target: { value: 'project-a' } });
        await screen.findByLabelText('归档合同');
        fireEvent.change(screen.getByLabelText('归档合同'), { target: { value: '__new__' } });
        fireEvent.click(screen.getByRole('button', { name: '预览归档与修改差异' }));
        await screen.findByText('确认本次归档');
        expect(fetch.mock.calls.some(([url]) => url.endsWith('/confirm'))).toBe(false);
        const preview = fetch.mock.calls.find(([url]) => url.endsWith('/preview'));
        expect(JSON.parse(preview[1].body).project_id).toBe('project-a');
        expect(screen.getByText('PRJ-A · 房武2标 / 新建合同')).not.toBeNull();
        fireEvent.click(screen.getByRole('button', { name: '确认归档并更新' }));
        await screen.findByText('合同编号：CON-A');
        const confirm = fetch.mock.calls.find(([url]) => url.endsWith('/confirm'));
        expect(JSON.parse(confirm[1].body)).toEqual({ token: 'review-token' });
    });
});
