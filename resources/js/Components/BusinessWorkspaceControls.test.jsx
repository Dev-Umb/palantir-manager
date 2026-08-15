// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { FieldControl } from './FieldControl';
import ProjectCustomerManager from './ProjectCustomerManager';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe('business workspace field controls', () => {
    it('uses the configured two-decimal step while allowing negative project amounts', () => {
        const { container } = render(<FieldControl
            field={{ key: 'occurred_amount', type: 'number', step: 0.01 }}
            value="-12.34"
            onChange={() => {}}
        />);
        const input = container.querySelector('input[type="number"]');

        expect(input).toHaveAttribute('step', '0.01');
        expect(input).not.toHaveAttribute('min');
        expect(input).toHaveValue(-12.34);
    });

    it('accepts multiple append uploads and renders existing attachment links', () => {
        const onChange = vi.fn();
        const { container } = render(<FieldControl
            field={{ key: 'contract_attachments', type: 'files' }}
            value={['/attachments/contract/0', '/attachments/contract/1']}
            onChange={onChange}
        />);
        const files = [
            new File(['a'], '合同一.pdf', { type: 'application/pdf' }),
            new File(['b'], '合同二.pdf', { type: 'application/pdf' }),
        ];
        fireEvent.change(container.querySelector('input[type="file"]'), { target: { files } });

        expect(screen.getAllByRole('link')).toHaveLength(2);
        expect(onChange).toHaveBeenCalledWith('contract_attachments', files);
    });

    it('uses account names instead of free text for responsible salesperson', () => {
        const onChange = vi.fn();
        render(<FieldControl
            field={{ key: 'business_owner_user_id', type: 'account' }}
            value="12"
            relationOptions={{ business_owner_user_id: { items: [{ id: 12, label: '业务员甲' }] } }}
            onChange={onChange}
        />);

        expect(screen.getByRole('button')).toHaveTextContent('业务员甲');
    });

    it('supports selecting multiple informed business accounts', () => {
        const onChange = vi.fn();
        render(<FieldControl
            field={{ key: 'informed_business_user_ids', type: 'multiaccount' }}
            value={['12']}
            relationOptions={{ informed_business_user_ids: { items: [{ id: '12', label: '业务员甲' }, { id: '13', label: '业务员乙' }] } }}
            onChange={onChange}
        />);

        fireEvent.click(screen.getByRole('button', { name: /业务员甲/ }));
        expect(screen.getByPlaceholderText('输入业务员姓名')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /业务员乙/ }));

        expect(onChange).toHaveBeenCalledWith('informed_business_user_ids', ['12', '13']);
    });
});

describe('project customer manager', () => {
    it('creates a customer and its drafted contact in one request and selects both records', async () => {
        const onCustomerSelected = vi.fn();
        const onContactSelected = vi.fn();
        vi.spyOn(globalThis, 'fetch').mockResolvedValue({
            ok: true,
            json: async () => ({
                customer: { id: 'customer-1', title: '演示客户', payload: { name: '演示客户' }, contacts: [] },
                contact: { id: 'contact-1', name: '张经理', phone: '13800138000', customer_id: 'customer-1' },
            }),
        });
        render(<ProjectCustomerManager customerId="" onCustomerSelected={onCustomerSelected} onContactSelected={onContactSelected} />);

        fireEvent.click(screen.getByRole('button', { name: /新增客户/ }));
        fireEvent.change(screen.getByLabelText('客户名称*'), { target: { value: '演示客户' } });
        fireEvent.change(screen.getByLabelText('联系人姓名*'), { target: { value: '张经理' } });
        fireEvent.change(screen.getByLabelText('联系电话'), { target: { value: '13800138000' } });
        fireEvent.click(screen.getByRole('button', { name: '保存客户' }));

        await waitFor(() => expect(fetch).toHaveBeenCalledWith('/project-customers', expect.objectContaining({ method: 'POST' })));
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual(expect.objectContaining({
            name: '演示客户',
            contact: { name: '张经理', phone: '13800138000' },
        }));
        expect(onCustomerSelected).toHaveBeenCalledWith('customer-1');
        expect(onContactSelected).toHaveBeenCalledWith('contact-1');
    });

    it('does not send an empty contact when saving a customer', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue({
            ok: true,
            json: async () => ({ customer: { id: 'customer-1', title: '演示客户', payload: { name: '演示客户' }, contacts: [] } }),
        });
        render(<ProjectCustomerManager customerId="" />);

        fireEvent.click(screen.getByRole('button', { name: /新增客户/ }));
        fireEvent.change(screen.getByLabelText('客户名称*'), { target: { value: '演示客户' } });
        fireEvent.click(screen.getByRole('button', { name: '保存客户' }));

        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        expect(JSON.parse(fetch.mock.calls[0][1].body)).not.toHaveProperty('contact');
    });

    it('keeps customer and contact drafts visible when combined saving fails', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue({
            ok: false,
            json: async () => ({ errors: { 'contact.name': ['联系人保存失败。'] } }),
        });
        render(<ProjectCustomerManager customerId="" />);

        fireEvent.click(screen.getByRole('button', { name: /新增客户/ }));
        fireEvent.change(screen.getByLabelText('客户名称*'), { target: { value: '保留草稿客户' } });
        fireEvent.change(screen.getByLabelText('联系人姓名*'), { target: { value: '保留草稿联系人' } });
        fireEvent.click(screen.getByRole('button', { name: '保存客户' }));

        expect(await screen.findByText('联系人保存失败。')).toBeInTheDocument();
        expect(screen.getByLabelText('客户名称*')).toHaveValue('保留草稿客户');
        expect(screen.getByLabelText('联系人姓名*')).toHaveValue('保留草稿联系人');
    });

    it('automatically saves an existing contact when its phone field loses focus', async () => {
        const onContactSelected = vi.fn();
        vi.spyOn(globalThis, 'fetch')
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ customer: {
                    id: 'customer-1',
                    title: '演示客户',
                    payload: { name: '演示客户' },
                    contacts: [{ id: 'contact-1', name: '张经理', phone: '13800138000' }],
                } }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    customer: { id: 'customer-1', title: '演示客户', payload: { name: '演示客户' }, contacts: [] },
                    contact: { id: 'contact-1', name: '张经理', phone: '13900139000', customer_id: 'customer-1' },
                }),
            });
        render(<ProjectCustomerManager customerId="customer-1" onContactSelected={onContactSelected} />);

        fireEvent.click(screen.getByRole('button', { name: /维护当前客户/ }));
        fireEvent.click(await screen.findByRole('button', { name: /张经理/ }));
        const phone = screen.getByLabelText('联系电话');
        fireEvent.change(phone, { target: { value: '13900139000' } });
        fireEvent.blur(phone);

        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
        expect(fetch.mock.calls[1][0]).toBe('/project-customers/customer-1');
        expect(JSON.parse(fetch.mock.calls[1][1].body).contact).toEqual({
            id: 'contact-1',
            name: '张经理',
            phone: '13900139000',
        });
        expect(onContactSelected).toHaveBeenCalledWith('contact-1');
        expect(await screen.findByRole('status')).toHaveTextContent('客户和联系人已自动保存');
    });

    it('queues the latest contact draft when another blur occurs during saving', async () => {
        let resolveFirstSave;
        const firstSave = new Promise((resolve) => {
            resolveFirstSave = resolve;
        });
        vi.spyOn(globalThis, 'fetch')
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ customer: {
                    id: 'customer-1',
                    title: '演示客户',
                    payload: { name: '演示客户' },
                    contacts: [{ id: 'contact-1', name: '张经理', phone: '13800138000' }],
                } }),
            })
            .mockReturnValueOnce(firstSave)
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    customer: { id: 'customer-1', title: '演示客户', payload: { name: '演示客户' }, contacts: [] },
                    contact: { id: 'contact-1', name: '张总', phone: '13900139000', customer_id: 'customer-1' },
                }),
            });
        render(<ProjectCustomerManager customerId="customer-1" />);

        fireEvent.click(screen.getByRole('button', { name: /维护当前客户/ }));
        fireEvent.click(await screen.findByRole('button', { name: /张经理/ }));
        const name = screen.getByLabelText('联系人姓名*');
        const phone = screen.getByLabelText('联系电话');
        fireEvent.change(name, { target: { value: '张总' } });
        fireEvent.blur(name);
        fireEvent.change(phone, { target: { value: '13900139000' } });
        fireEvent.blur(phone);

        expect(fetch).toHaveBeenCalledTimes(2);
        resolveFirstSave({
            ok: true,
            json: async () => ({
                customer: { id: 'customer-1', title: '演示客户', payload: { name: '演示客户' }, contacts: [] },
                contact: { id: 'contact-1', name: '张总', phone: '13800138000', customer_id: 'customer-1' },
            }),
        });

        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(3));
        expect(JSON.parse(fetch.mock.calls[2][1].body).contact).toEqual({
            id: 'contact-1',
            name: '张总',
            phone: '13900139000',
        });
    });

    it('prevents Enter in embedded customer fields from submitting the project form', () => {
        const submitProject = vi.fn((event) => event.preventDefault());
        render(
            <form onSubmit={submitProject}>
                <ProjectCustomerManager customerId="" />
            </form>,
        );

        fireEvent.click(screen.getByRole('button', { name: /新增客户/ }));
        const enterEventAccepted = fireEvent.keyDown(screen.getByLabelText('客户名称*'), {
            key: 'Enter',
            code: 'Enter',
        });

        expect(enterEventAccepted).toBe(false);
        expect(submitProject).not.toHaveBeenCalled();
    });

    it('locks customer saves synchronously and reports the related-data busy state', async () => {
        let resolveRequest;
        const onSavingChange = vi.fn();
        const request = new Promise((resolve) => {
            resolveRequest = resolve;
        });
        vi.spyOn(globalThis, 'fetch').mockReturnValue(request);
        render(<ProjectCustomerManager customerId="" onSavingChange={onSavingChange} />);

        fireEvent.click(screen.getByRole('button', { name: /新增客户/ }));
        fireEvent.change(screen.getByLabelText('客户名称*'), { target: { value: '演示客户' } });
        const saveButton = screen.getByRole('button', { name: '保存客户' });
        saveButton.click();
        saveButton.click();

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(onSavingChange).toHaveBeenCalledWith(true);

        resolveRequest({
            ok: true,
            json: async () => ({ customer: { id: 'customer-1', title: '演示客户', payload: { name: '演示客户' }, contacts: [] } }),
        });

        await waitFor(() => expect(onSavingChange).toHaveBeenLastCalledWith(false));
    });
});
