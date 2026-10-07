// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import QuotationArtifact from './QuotationArtifact';

const artifact = (data = {}) => ({ id: 'quote-1', type: 'quotation_docx', data: { title: '测试项目报价单', date: '2026-10-07', contact: '测试业务员', phone: '10000000000', tax_rate: '13', shipping: '含运费', items: [{ name: 'BBU洞室模板', price: '6350', unit: '吨', material_price: null, processing_price: null }], ...data } });
afterEach(() => { cleanup(); vi.unstubAllGlobals(); });

it('keeps supplied comprehensive price and asks only for missing unit without requiring a breakdown', async () => {
    const fetch = vi.fn(); vi.stubGlobal('fetch', fetch);
    render(<QuotationArtifact artifact={artifact({ items: [{ name: 'BBU洞室模板', price: '6350', unit: null }] })} runId="run-1" />);
    expect(screen.getByLabelText('产品1综合单价').value).toBe('6350');
    fireEvent.click(screen.getByRole('button', { name: '生成盖章报价单' }));
    expect(await screen.findByRole('alert')).not.toBeNull();
    expect(fetch).not.toHaveBeenCalled();
    expect(screen.queryByText(/网价/)).toBeNull();
});

it('submits zero price and no invented split, then shows owner download in the same chat card', async () => {
    const done = artifact({ generated: true });
    const fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ artifact: done }) });vi.stubGlobal('fetch', fetch);
    render(<QuotationArtifact artifact={artifact({ items: [{ name: '模板', price: '0', unit: '吨' }] })} runId="run-1" />);
    fireEvent.click(screen.getByRole('button', { name: '生成盖章报价单' }));
    const download = await screen.findByRole('link', { name: '下载盖章报价单 DOCX' });
    expect(download.getAttribute('href')).toBe('/ai/runs/run-1/quotations/quote-1/download');
    expect(download.hasAttribute('download')).toBe(true);
    expect(screen.getByRole('link', { name: '下载盖章报价单 PDF' }).getAttribute('href')).toBe('/ai/runs/run-1/quotations/quote-1/download?format=pdf');
    expect(screen.getByText('报价文件：quotation.docx / quotation.pdf')).not.toBeNull();
    expect(screen.getByLabelText('已生成报价文件').compareDocumentPosition(screen.getByLabelText('报价标题')) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    const body = JSON.parse(fetch.mock.calls[0][1].body);
    expect(body.items[0]).toMatchObject({ price: '0', material_price: null, processing_price: null });
    expect(screen.getByLabelText('报价标题').disabled).toBe(true);
});

it('retains entered values when generation fails and allows retry', async () => {
    const fetch = vi.fn().mockResolvedValueOnce({ ok: false, json: async () => ({ message: '服务暂时失败' }) });vi.stubGlobal('fetch', fetch);
    render(<QuotationArtifact artifact={artifact()} runId="run-1" />);
    fireEvent.change(screen.getByLabelText('报价标题'), { target: { value: '修改后的报价标题' } });
    fireEvent.click(screen.getByRole('button', { name: '生成盖章报价单' }));
    expect((await screen.findByRole('alert')).textContent).toContain('服务暂时失败');
    expect(screen.getByLabelText('报价标题').value).toBe('修改后的报价标题');
    expect(screen.getByRole('button', { name: '生成盖章报价单' }).disabled).toBe(false);
});

it('blocks conflicting fixed terms and running actions while retaining historical download', async () => {
    const fetch = vi.fn();vi.stubGlobal('fetch', fetch);
    const { rerender } = render(<QuotationArtifact artifact={artifact({ tax_rate: '3' })} runId="run-1" />);
    fireEvent.click(screen.getByRole('button', { name: '生成盖章报价单' }));
    expect((await screen.findByRole('alert')).textContent).toContain('冲突');
    expect(fetch).not.toHaveBeenCalled();
    rerender(<QuotationArtifact artifact={artifact({ generated: true })} runId="run-1" canAct={false} />);
    expect(await screen.findByRole('link', { name: '下载盖章报价单 DOCX' })).not.toBeNull();
});
