import ComboBox from './ComboBox';

export function FieldControl({ field, value, onChange, relationOptions = {}, autoFocus = false }) {
    if (field.type === 'file') {
        return (
            <div className="file-control">
                {typeof value === 'string' && value && <a href={value} target="_blank" rel="noreferrer">查看已上传附件</a>}
                <input name={field.key} type="file" required={!!field.required && !value} autoFocus={autoFocus} onChange={(event) => onChange(field.key, event.target.files?.[0] || '')} />
            </div>
        );
    }

    const common = {
        name: field.key,
        value: value ?? '',
        required: !!field.required,
        autoFocus,
        onChange: (event) => onChange(field.key, event.target.value),
    };

    if (field.type === 'relation') {
        const items = relationOptions[field.key]?.items || [];
        const hasValue = value && items.some((item) => item.id === value);
        const options = [
            { value: '', label: '未选择' },
            ...(value && !hasValue ? [{ value, label: '当前关联不可用' }] : []),
            ...items.map((item) => ({ value: item.id, label: item.label })),
        ];

        return <ComboBox value={common.value} options={options} onChange={(next) => onChange(field.key, next)} autoFocus={autoFocus} />;
    }

    if (field.type === 'select' && field.options?.length) {
        const options = [
            { value: '', label: '未选择' },
            ...field.options.map((option) => ({ value: option, label: option })),
        ];

        return <ComboBox value={common.value} options={options} onChange={(next) => onChange(field.key, next)} autoFocus={autoFocus} />;
    }

    if (field.type === 'date') {
        return <input {...common} type="date" />;
    }

    if (field.type === 'number') {
        return <input {...common} type="number" step="any" />;
    }

    return <input {...common} type="text" />;
}

export function SchemaForm({ fields, data, setData, submitLabel = '保存', processing, relationOptions = {} }) {
    const editable = fields.filter((field) => !['readonly', 'lookup', 'derived'].includes(field.type));

    return (
        <div className="form-grid">
            {editable.map((field) => (
                <label key={field.key}>
                    <span>{field.label}{field.required && <b>*</b>}</span>
                    <FieldControl field={field} value={data[field.key]} onChange={(key, value) => setData(key, value)} relationOptions={relationOptions} />
                </label>
            ))}
            <button type="submit" disabled={processing}>{processing ? '提交中...' : submitLabel}</button>
        </div>
    );
}
