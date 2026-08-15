// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ProjectCustomerInlineFields, {
    CustomerProfileConflictDialog,
    normalizedProjectCustomerProfile,
} from './ProjectCustomerInlineFields';

const profile = {
    customer_id: 'customer-1',
    name: '甲客户',
    address: '武汉市江岸区',
    level: 'A',
    customer_nature: '国央企',
    overwrite_confirmed: false,
    contacts: [
        { id: 'contact-1', name: '李经理', phone: '13800000000' },
        { id: '', name: '王经理', phone: '13900000000' },
    ],
};

describe('ProjectCustomerInlineFields', () => {
    afterEach(cleanup);

    it('flattens the complete customer profile and only unlinks a removed contact', () => {
        const onChange = vi.fn();
        render(<ProjectCustomerInlineFields profile={profile} onChange={onChange} />);

        expect(screen.getByLabelText('客户名称*')).toHaveValue('甲客户');
        expect(screen.getByLabelText('客户地址')).toHaveValue('武汉市江岸区');
        expect(screen.getByLabelText('客户等级')).toHaveValue('A');
        expect(screen.getByLabelText('客户性质')).toHaveValue('国央企');
        expect(screen.queryByText('客户备注')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: '移除联系人 李经理' }));

        expect(onChange).toHaveBeenCalledWith({
            ...profile,
            overwrite_confirmed: false,
            contacts: [{ id: '', name: '王经理', phone: '13900000000' }],
        });
    });

    it('selects an existing customer by name and address and resets project contacts', () => {
        const onChange = vi.fn();
        render(
            <ProjectCustomerInlineFields
                profile={{ ...profile, customer_id: '', name: '', address: '', contacts: [] }}
                onChange={onChange}
                customerOptions={{
                    items: [{
                        id: 'customer-2',
                        label: '乙客户',
                        meta: { name: '乙客户', address: '武汉市武昌区', level: 'B', customer_nature: '私企' },
                    }],
                }}
            />,
        );

        fireEvent.focus(screen.getByLabelText('客户名称*'));
        fireEvent.click(screen.getByRole('button', { name: /乙客户/ }));

        expect(onChange).toHaveBeenCalledWith({
            customer_id: 'customer-2',
            name: '乙客户',
            address: '武汉市武昌区',
            level: 'B',
            customer_nature: '私企',
            overwrite_confirmed: false,
            contacts: [],
        });
    });

    it('normalizes unique-key fields and excludes unfinished blank contact rows', () => {
        expect(normalizedProjectCustomerProfile({
            ...profile,
            name: ' 甲客户 ',
            address: ' 武汉市江岸区 ',
            contacts: [
                { id: '', name: ' ', phone: '123' },
                { id: '', name: ' 李经理 ', phone: ' 13800000000 ' },
            ],
        }, true)).toEqual({
            customer_id: 'customer-1',
            name: '甲客户',
            address: '武汉市江岸区',
            level: 'A',
            customer_nature: '国央企',
            overwrite_confirmed: true,
            contacts: [{ id: null, name: '李经理', phone: '13800000000' }],
        });
    });

    it('requires an explicit confirmation before shared customer fields are overwritten', () => {
        const onCancel = vi.fn();
        const onConfirm = vi.fn();
        render(
            <CustomerProfileConflictDialog
                conflicts={[{ field: 'level', label: '客户等级', current: 'B', submitted: 'A' }]}
                onCancel={onCancel}
                onConfirm={onConfirm}
            />,
        );

        expect(screen.getByText('客户资料存在冲突')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: '取消，返回修改' }));
        fireEvent.click(screen.getByRole('button', { name: '确认覆盖并保存' }));
        expect(onCancel).toHaveBeenCalledOnce();
        expect(onConfirm).toHaveBeenCalledOnce();
    });
});
