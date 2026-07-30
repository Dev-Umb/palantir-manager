// @vitest-environment jsdom

import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CustomerContactCell from './CustomerContactCell';

describe('CustomerContactCell', () => {
    afterEach(cleanup);

    it('renders only the primary contact as a one-line summary', () => {
        const onOpen = vi.fn();

        render(
            <CustomerContactCell
                contacts={[
                    { id: 'contact-1', name: '李经理', phone: '13900000000' },
                    { id: 'contact-2', name: '王工', phone: '13800000000' },
                ]}
                onOpen={onOpen}
            />,
        );

        expect(screen.getByText('李经理 · 13900000000')).toBeInTheDocument();
        expect(screen.getByText('+1')).toBeInTheDocument();
        expect(screen.queryByText('王工')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /查看.*详情/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: '新增联系人' })).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: '李经理 · 13900000000，共 2 项' }));
        expect(onOpen).toHaveBeenCalledOnce();
    });

    it('uses the contact name without a phone placeholder', () => {
        render(<CustomerContactCell contacts={[{ id: 'contact-1', name: '李经理', phone: '' }]} onOpen={() => {}} />);

        expect(screen.getByText('李经理')).toBeInTheDocument();
        expect(screen.queryByText(/未填写/)).not.toBeInTheDocument();
    });

    it('shows only the unified empty marker when there are no contacts', () => {
        render(<CustomerContactCell contacts={[]} onOpen={() => {}} />);

        expect(screen.getByText('—')).toHaveClass('empty-value');
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});
