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

    it('renders its option menu outside clipping containers', () => {
        const { container } = render(
            <div style={{ overflow: 'hidden' }}>
                <MultiComboBox
                    value={[]}
                    items={[{ id: 'contact-1', label: '李经理 · 13900000000' }]}
                    onChange={() => {}}
                />
            </div>,
        );

        fireEvent.click(screen.getByRole('button', { name: '未选择' }));

        const menu = document.querySelector('.multi-combo-menu');
        expect(menu).toBeInTheDocument();
        expect(container.contains(menu)).toBe(false);
        expect(menu).toHaveClass('ag-custom-component-popup');
        expect(menu.style.position).toBe('fixed');
        expect(screen.getByText('李经理 · 13900000000')).toBeInTheDocument();
    });
});
