// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ComboBox from './ComboBox';
import CreatableComboBox from './CreatableComboBox';
import { FieldControl } from './FieldControl';
import MultiComboBox from './MultiComboBox';

beforeEach(() => {
    vi.useFakeTimers();
    global.fetch = vi.fn();
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.restoreAllMocks();
});

async function flushSearch() {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(250);
    });
}

describe('remote relation inputs', () => {
    it('keeps existing attachment access while localizing replacement selection', () => {
        const onChange = vi.fn();

        render(
            <FieldControl
                field={{ key: 'attachment', label: '附件', type: 'file' }}
                value="/attachments/existing"
                onChange={onChange}
            />,
        );

        expect(screen.getByRole('link', { name: '查看已上传附件' }))
            .toHaveAttribute('href', '/attachments/existing');
        expect(screen.getByRole('button', { name: '选择文件' })).toBeInTheDocument();

        const replacement = new File(['replacement'], '替换附件.pdf', { type: 'application/pdf' });
        fireEvent.change(screen.getByLabelText('选择文件'), { target: { files: [replacement] } });
        expect(onChange).toHaveBeenCalledWith('attachment', replacement);
    });

    it('searches relation options on the server with the current form context', async () => {
        global.fetch.mockResolvedValue({
            ok: true,
            json: async () => ({ items: [{ id: 'project-2', label: 'PRJ-002 · 乙项目' }] }),
        });
        const onChange = vi.fn();

        render(
            <ComboBox
                value="project-1"
                options={[{ value: 'project-1', label: 'PRJ-001 · 甲项目' }]}
                searchUrl="/relation-options?source_object=drawing&field=project_id"
                searchContext={{ customer_id: 'customer-1' }}
                onChange={onChange}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /PRJ-001/ }));
        fireEvent.change(screen.getByPlaceholderText('输入关键字搜索'), { target: { value: '乙项目' } });
        await flushSearch();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        const requested = new URL(global.fetch.mock.calls[0][0], 'http://localhost');
        expect(requested.searchParams.get('q')).toBe('乙项目');
        expect(requested.searchParams.get('context[customer_id]')).toBe('customer-1');
        expect(screen.getByRole('button', { name: /PRJ-002/ })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /PRJ-002/ }));
        expect(onChange).toHaveBeenCalledWith('project-2');
    });

    it('selects a regular dropdown option with arrow keys and Enter', () => {
        const onChange = vi.fn();
        render(
            <ComboBox
                value=""
                options={[
                    { value: '', label: '未选择' },
                    { value: 'draft', label: '草稿' },
                ]}
                onChange={onChange}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: '未选择' }));
        const input = screen.getByPlaceholderText('输入关键字搜索');
        fireEvent.change(input, { target: { value: '草稿' } });
        fireEvent.keyDown(input, { key: 'ArrowDown' });
        fireEvent.keyDown(input, { key: 'Enter' });

        expect(onChange).toHaveBeenCalledWith('draft');
    });

    it('aborts an obsolete request when the search keyword changes', async () => {
        const requests = [];
        global.fetch.mockImplementation((url, options) => new Promise((resolve) => {
            requests.push({ url, options, resolve });
        }));

        render(
            <ComboBox
                value=""
                options={[]}
                searchUrl="/relation-options?source_object=drawing&field=project_id"
                onChange={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: '未选择' }));
        fireEvent.change(screen.getByPlaceholderText('输入关键字搜索'), { target: { value: '甲' } });
        await flushSearch();
        expect(requests).toHaveLength(1);

        fireEvent.change(screen.getByPlaceholderText('输入关键字搜索'), { target: { value: '乙' } });
        expect(requests[0].options.signal.aborted).toBe(true);
        await flushSearch();
        expect(requests).toHaveLength(2);

        requests[1].resolve({ ok: true, json: async () => ({ items: [] }) });
        await act(async () => Promise.resolve());
    });

    it('offers server matches while preserving direct entry for a new material', async () => {
        global.fetch.mockResolvedValue({
            ok: true,
            json: async () => ({ items: [{ id: 'material-1', label: '钢板 · Q235B · 10mm' }] }),
        });
        const onChange = vi.fn();

        const { rerender } = render(
            <CreatableComboBox
                value=""
                items={[]}
                searchUrl="/relation-options?source_object=inbound&field=material_id"
                onChange={onChange}
            />,
        );

        const input = screen.getByPlaceholderText('搜索已有物资，或直接输入新名称');
        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: '钢板' } });
        expect(onChange).toHaveBeenLastCalledWith('钢板');
        await flushSearch();

        expect(screen.getByRole('button', { name: /钢板 · Q235B/ })).toBeInTheDocument();
        fireEvent.keyDown(input, { key: 'ArrowDown' });
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(onChange).toHaveBeenLastCalledWith('material-1');
        rerender(
            <CreatableComboBox
                value="material-1"
                items={[]}
                searchUrl="/relation-options?source_object=inbound&field=material_id"
                onChange={onChange}
            />,
        );
        expect(input).toHaveValue('钢板 · Q235B · 10mm');

        fireEvent.change(input, { target: { value: '新型材料' } });
        expect(onChange).toHaveBeenLastCalledWith('新型材料');
    });

    it('searches contacts remotely and keeps an unavailable selected contact removable', async () => {
        global.fetch.mockResolvedValue({
            ok: true,
            json: async () => ({ items: [{ id: 'contact-2', label: '李四 · 13900000000' }] }),
        });
        const onChange = vi.fn();

        render(
            <MultiComboBox
                value={['contact-old']}
                items={[]}
                selectedItems={[{ id: 'contact-old', label: '历史联系人' }]}
                searchUrl="/relation-options?source_object=project&field=customer_contact_ids"
                searchContext={{ customer_id: 'customer-2' }}
                onChange={onChange}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /历史联系人/ }));
        fireEvent.change(screen.getByPlaceholderText('输入联系人姓名或手机号'), { target: { value: '李四' } });
        await flushSearch();

        const requested = new URL(global.fetch.mock.calls[0][0], 'http://localhost');
        expect(requested.searchParams.get('context[customer_id]')).toBe('customer-2');
        const contactSearch = screen.getByPlaceholderText('输入联系人姓名或手机号');
        fireEvent.keyDown(contactSearch, { key: 'ArrowDown' });
        fireEvent.keyDown(contactSearch, { key: 'Enter' });
        expect(onChange).toHaveBeenCalledWith(['contact-old', 'contact-2']);
        expect(screen.getByText(/历史联系人（历史关联）/)).toBeInTheDocument();
    });

    it('shows a historical single relation only while it remains selected', () => {
        const onChange = vi.fn();
        const field = { key: 'customer_id', label: '客户', type: 'relation' };
        const relationOptions = {
            customer_id: {
                items: [{ id: 'customer-active', label: '启用客户' }],
                selectedItems: [{ id: 'customer-old', label: '历史客户' }],
            },
        };
        const { rerender } = render(
            <FieldControl field={field} value="customer-old" relationOptions={relationOptions} onChange={onChange} />,
        );

        expect(screen.getByRole('button', { name: /历史客户/ })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /历史客户/ }));
        fireEvent.click(screen.getByRole('button', { name: '未选择' }));
        expect(onChange).toHaveBeenCalledWith('customer_id', '');

        rerender(<FieldControl field={field} value="" relationOptions={relationOptions} onChange={onChange} />);
        fireEvent.click(screen.getByRole('button', { name: '未选择' }));
        expect(screen.queryByText(/历史客户/)).not.toBeInTheDocument();
    });
});
