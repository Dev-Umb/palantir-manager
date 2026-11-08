// @vitest-environment jsdom
import { useState } from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { FieldControl } from './FieldControl';
import { formatObjectNumber } from './objectNumberFormatting';
const field = { key: 'first_shipment_date', label: '首次发货日期', type: 'date', allow_year: true };
afterEach(cleanup);
function Editor({ initial = '2016' }) {
    const [value, setValue] = useState(initial);
    return <FieldControl field={field} value={value} onChange={(_, next) => setValue(next)} />;
}
it('preserves year precision through editing and clearing without inventing a day', () => {
    render(<Editor />);
    expect(screen.getByLabelText(field.label).value).toBe('2016');
    fireEvent.change(screen.getByLabelText(field.label), { target: { value: '2022' } });
    expect(screen.getByLabelText(field.label).value).toBe('2022');
    fireEvent.change(screen.getByLabelText(`${field.label}精度`), { target: { value: 'day' } });
    expect(screen.getByLabelText(field.label).value).toBe('');
    fireEvent.input(screen.getByLabelText(field.label), { target: { value: '2022-06-01' } });
    expect(screen.getByLabelText(field.label).value).toBe('2022-06-01');
    fireEvent.change(screen.getByLabelText(`${field.label}精度`), { target: { value: 'year' } });
    expect(screen.getByLabelText(field.label).value).toBe('2022');
    fireEvent.change(screen.getByLabelText(field.label), { target: { value: '' } });
    expect(screen.getByLabelText(field.label).value).toBe('');
});
it('keeps full dates and other date fields on their existing date controls', () => {
    const { unmount } = render(<Editor initial="2024-02-29" />);
    expect(screen.getByLabelText(field.label).type).toBe('date');
    expect(screen.getByLabelText(field.label).value).toBe('2024-02-29');
    unmount();
    const next = render(<FieldControl field={{ ...field, key: 'last_shipment_date', allow_year: false }} value="2024-02-29" onChange={vi.fn()} />);
    expect(next.container.querySelector('input').type).toBe('date');
    expect(next.container.querySelector('select')).toBeNull();
});
it('labels uncertainty only for the scoped project field', () => {
    expect(formatObjectNumber('project', field, '2016')).toBe('2016年（月日不详）');
    expect(formatObjectNumber('project', field, '2016-01-01')).toBe('2016-01-01');
    expect(formatObjectNumber('purchase', field, '2016')).toBe('2016');
});

it('restores precision when a refreshed record or rollback replaces the value', () => {
    const { rerender } = render(<FieldControl field={field} value="2016" onChange={vi.fn()} />);
    rerender(<FieldControl field={field} value="2016-06-01" onChange={vi.fn()} />);
    expect(screen.getByLabelText(field.label).type).toBe('date');
    expect(screen.getByLabelText(field.label).value).toBe('2016-06-01');
    rerender(<FieldControl field={field} value="2022" onChange={vi.fn()} />);
    expect(screen.getByLabelText(field.label).type).toBe('text');
    expect(screen.getByLabelText(field.label).value).toBe('2022');
});
