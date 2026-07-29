// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import MultiComboBox from './MultiComboBox';

afterEach(cleanup);

describe('MultiComboBox historical selections', () => {
    it('shows and removes selected unavailable items without making them selectable again', () => {
        const onChange = vi.fn();
        const { rerender } = render(
            <MultiComboBox
                value={['contact-inactive']}
                items={[{ id: 'contact-active', label: '启用联系人' }]}
                selectedItems={[{ id: 'contact-inactive', label: '停用联系人' }]}
                onChange={onChange}
            />,
        );

        expect(screen.getByRole('button', { name: /停用联系人/ })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /停用联系人/ }));
        fireEvent.click(screen.getByRole('button', { name: /停用联系人.*历史关联/ }));
        expect(onChange).toHaveBeenLastCalledWith([]);

        rerender(
            <MultiComboBox
                value={[]}
                items={[{ id: 'contact-active', label: '启用联系人' }]}
                selectedItems={[{ id: 'contact-inactive', label: '停用联系人' }]}
                onChange={onChange}
            />,
        );
        expect(screen.queryByText(/停用联系人/)).not.toBeInTheDocument();
    });
});
