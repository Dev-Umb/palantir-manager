import { useEffect, useState } from 'react';

export default function FirstShipmentDateControl({ field, value, onChange, autoFocus }) {
    const [precision, setPrecision] = useState(/^[1-9]\d{3}$/.test(String(value ?? '')) ? 'year' : 'day');
    useEffect(() => {
        if (/^[1-9]\d{3}$/.test(String(value ?? ''))) setPrecision('year');
        else if (/^[1-9]\d{3}-\d{2}-\d{2}$/.test(String(value ?? ''))) setPrecision('day');
    }, [value]);
    return <div>
        <select aria-label={`${field.label}精度`} value={precision} onChange={(event) => {
            const next = event.target.value;
            setPrecision(next);
            onChange(field.key, next === 'year' ? String(value ?? '').slice(0, 4) : '');
        }}>
            <option value="day">完整日期</option><option value="year">仅年份（月日不详）</option>
        </select>
        {precision === 'year'
            ? <input aria-label={field.label} name={field.key} type="text" inputMode="numeric" pattern="[1-9][0-9]{3}" maxLength={4} placeholder="例如 2016" value={value ?? ''} required={!!field.required} autoFocus={autoFocus} onChange={(event) => onChange(field.key, event.target.value)} />
            : <input aria-label={field.label} name={field.key} type="date" value={value ?? ''} required={!!field.required} autoFocus={autoFocus} onInput={(event) => onChange(field.key, event.currentTarget.value)} />}
    </div>;
}
