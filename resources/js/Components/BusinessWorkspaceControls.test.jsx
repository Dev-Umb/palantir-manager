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
    it('creates a customer through the restricted project API and selects the original record id', async () => {
        const onCustomerSelected = vi.fn();
        vi.spyOn(globalThis, 'fetch').mockResolvedValue({
            ok: true,
            json: async () => ({ customer: { id: 'customer-1', title: '演示客户', payload: { name: '演示客户' }, contacts: [] } }),
        });
        render(<ProjectCustomerManager customerId="" onCustomerSelected={onCustomerSelected} />);

        fireEvent.click(screen.getByRole('button', { name: /新增客户/ }));
        fireEvent.change(screen.getByLabelText('客户名称*'), { target: { value: '演示客户' } });
        fireEvent.click(screen.getByRole('button', { name: '保存客户' }));

        await waitFor(() => expect(fetch).toHaveBeenCalledWith('/project-customers', expect.objectContaining({ method: 'POST' })));
        expect(onCustomerSelected).toHaveBeenCalledWith('customer-1');
    });
});
