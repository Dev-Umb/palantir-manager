// @vitest-environment jsdom

import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CustomerContactCell from './CustomerContactCell';

describe('CustomerContactCell', () => {
    afterEach(cleanup);

    it('renders each contact as a compact name and phone row', () => {
        const onDetail = vi.fn();

        render(
            <CustomerContactCell
                contacts={[
                    { id: 'contact-1', name: '李经理', phone: '13900000000' },
                    { id: 'contact-2', name: '王工', phone: '13800000000' },
                ]}
                onDetail={onDetail}
            />,
        );

        const list = screen.getByRole('list', { name: '客户联系人' });
        expect(within(list).getAllByRole('listitem')).toHaveLength(2);
        expect(within(list).getByText('李经理')).toBeInTheDocument();
        expect(within(list).getByText('13900000000')).toBeInTheDocument();
        expect(within(list).queryByText('采购经理')).not.toBeInTheDocument();

        fireEvent.click(within(list).getByRole('button', { name: '查看李经理详情' }));
        expect(onDetail).toHaveBeenCalledWith(expect.objectContaining({ id: 'contact-1' }));
    });

    it('shows an explicit empty value and create action when allowed', () => {
        const onCreate = vi.fn();

        render(<CustomerContactCell contacts={[]} canCreate onCreate={onCreate} />);

        expect(screen.getByText('暂无联系人')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: '新增联系人' }));
        expect(onCreate).toHaveBeenCalledOnce();
    });
});
