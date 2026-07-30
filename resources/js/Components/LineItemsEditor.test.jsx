// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import LineItemsEditor from './LineItemsEditor';

const fields = [
    { key: 'spec', label: '规格', type: 'text', scope: 'item', required: true },
    { key: 'qty', label: '数量', type: 'number', scope: 'item', required: true },
];

afterEach(cleanup);

describe('LineItemsEditor', () => {
    it('edits by stable item id and supports adding/removing without reaching zero rows', () => {
        const onChange = vi.fn();
        const items = [
            { id: 'item-1', spec: '10mm', qty: 1 },
            { id: 'item-2', spec: '12mm', qty: 2 },
        ];
        const { rerender } = render(<LineItemsEditor fields={fields} items={items} onChange={onChange} relationOptions={{}} />);

        const addButton = screen.getByRole('button', { name: '新增明细' });
        expect(addButton).toHaveClass('secondary-button', 'line-items-add');
        expect(within(addButton).getByText('新增明细')).toHaveClass('line-items-add-label');

        const second = screen.getByRole('group', { name: '明细 2' });
        fireEvent.change(within(second).getByLabelText('数量*'), { target: { value: '9' } });
        expect(onChange).toHaveBeenLastCalledWith([
            items[0],
            { ...items[1], qty: '9' },
        ]);

        fireEvent.click(screen.getByRole('button', { name: '新增明细' }));
        expect(onChange.mock.calls.at(-1)[0]).toHaveLength(3);

        rerender(<LineItemsEditor fields={fields} items={[items[0]]} onChange={onChange} relationOptions={{}} />);
        expect(screen.getByRole('button', { name: '删除明细 1' })).toBeDisabled();
    });
});
