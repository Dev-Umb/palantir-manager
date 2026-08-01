import ComboBox from './ComboBox';
import CreatableComboBox from './CreatableComboBox';
import LocalizedFileInput from './LocalizedFileInput';
import MultiComboBox from './MultiComboBox';

export function FieldControl({ field, value, onChange, relationOptions = {}, autoFocus = false }) {
    if (field.type === 'files') {
        const existing = Array.isArray(value) ? value.filter((item) => typeof item === 'string') : [];
        const pending = Array.isArray(value) ? value.filter((item) => typeof File !== 'undefined' && item instanceof File) : [];

        return (
            <div className="file-control multiple-file-control">
                {existing.length > 0 && (
                    <div className="attachment-list">
                        {existing.map((url, index) => <a key={`${url}-${index}`} href={url} target="_blank" rel="noreferrer">附件 {index + 1}</a>)}
                    </div>
                )}
                {pending.length > 0 && <small>本次新增 {pending.length} 个附件，保存后叠加到历史附件。</small>}
                <input
                    name={field.key}
                    type="file"
                    multiple
                    accept=".pdf,.jpg,.jpeg,.png"
                    onChange={(event) => onChange(field.key, Array.from(event.target.files || []))}
                />
            </div>
        );
    }

    if (field.type === 'file') {
        return (
            <div className="file-control">
                {typeof value === 'string' && value && <a href={value} target="_blank" rel="noreferrer">查看已上传附件</a>}
                <LocalizedFileInput
                    name={field.key}
                    file={typeof File !== 'undefined' && value instanceof File ? value : null}
                    required={!!field.required && !value}
                    autoFocus={autoFocus}
                    onChange={(file) => onChange(field.key, file || '')}
                />
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
        const relation = relationOptions[field.key] || {};
        const items = relation.items || [];
        const hasValue = value && items.some((item) => item.id === value);
        const historical = value && !hasValue
            ? (relation.selectedItems || []).find((item) => item.id === value)
            : null;
        const options = [
            { value: '', label: '未选择' },
            ...(value && !hasValue ? [{
                value,
                label: historical ? `${historical.label}（历史关联）` : '当前关联不可用',
            }] : []),
            ...items.map((item) => ({ value: item.id, label: item.label })),
        ];

        return (
            <ComboBox
                value={common.value}
                options={options}
                searchUrl={relation.search_url}
                searchContext={relation.search_context}
                onChange={(next) => onChange(field.key, next)}
                autoFocus={autoFocus}
            />
        );
    }

    if (field.type === 'account') {
        const items = relationOptions[field.key]?.items || [];
        const options = [
            { value: '', label: '未选择' },
            ...items.map((item) => ({ value: String(item.id), label: item.label })),
        ];

        return <ComboBox value={value === null || value === undefined ? '' : String(value)} options={options} onChange={(next) => onChange(field.key, next)} autoFocus={autoFocus} />;
    }

    if (field.type === 'multirelation') {
        const options = relationOptions[field.key] || {};

        return (
            <MultiComboBox
                value={Array.isArray(value) ? value : []}
                items={options.items || []}
                selectedItems={options.selectedItems || []}
                searchUrl={options.search_url}
                searchContext={options.search_context}
                onChange={(next) => onChange(field.key, next)}
            />
        );
    }

    if (field.type === 'creatable_relation') {
        return (
            <CreatableComboBox
                value={value ?? ''}
                items={relationOptions[field.key]?.items || []}
                selectedItems={relationOptions[field.key]?.selectedItems || []}
                searchUrl={relationOptions[field.key]?.search_url}
                searchContext={relationOptions[field.key]?.search_context}
                onChange={(next) => onChange(field.key, next)}
                autoFocus={autoFocus}
            />
        );
    }

    if (field.type === 'select' && field.options?.length) {
        const options = [
            { value: '', label: '未选择' },
            ...field.options.map((option) => ({ value: option, label: option })),
        ];

        return <ComboBox value={common.value} options={options} onChange={(next) => onChange(field.key, next)} autoFocus={autoFocus} />;
    }

    if (field.type === 'date') {
        return (
            <input
                name={field.key}
                value={value ?? ''}
                required={!!field.required}
                autoFocus={autoFocus}
                type="date"
                onInput={(event) => onChange(field.key, event.currentTarget.value)}
            />
        );
    }

    if (field.type === 'number') {
        return <input {...common} type="number" step="any" min={field.min} max={field.max} />;
    }

    if (field.type === 'range') {
        const progress = Number(value || 0);
        return (
            <div className="range-control">
                <input
                    name={field.key}
                    type="range"
                    min={field.min ?? 0}
                    max={field.max ?? 100}
                    step={field.step ?? 1}
                    value={progress}
                    onChange={(event) => onChange(field.key, Number(event.target.value))}
                    autoFocus={autoFocus}
                />
                <output>{progress}%</output>
            </div>
        );
    }

    return <input {...common} type="text" />;
}

export function SchemaForm({ fields, data, setData, submitLabel = '保存', processing, relationOptions = {}, children }) {
    const editable = fields.filter((field) => field.scope !== 'item'
        && !field.readonly
        && !['readonly', 'lookup', 'derived'].includes(field.type));

    return (
        <div className="form-grid">
            {editable.map((field) => (
                ['file', 'files'].includes(field.type) ? (
                    <div key={field.key} className={`form-field ${fieldLayoutClass(field)}`}>
                        <span>{field.label}{field.required && <b>*</b>}</span>
                        <FieldControl field={field} value={data[field.key]} onChange={(key, value) => setData(key, value)} relationOptions={relationOptions} />
                    </div>
                ) : (
                    <label key={field.key} className={fieldLayoutClass(field)}>
                        <span>{field.label}{field.required && <b>*</b>}</span>
                        <FieldControl field={field} value={data[field.key]} onChange={(key, value) => setData(key, value)} relationOptions={relationOptions} />
                    </label>
                )
            ))}
            {children}
            <div className="form-actions">
                <span>标有 <b>*</b> 的项目必须填写</span>
                <button type="submit" disabled={processing}>{processing ? '正在保存...' : submitLabel}</button>
            </div>
        </div>
    );
}

function fieldLayoutClass(field) {
    if (['file', 'files', 'multirelation'].includes(field.type)) return 'wide';
    if (['remark', 'risk', 'reason', 'address', 'cooperation_history', 'description'].includes(field.key)) return 'wide';

    return '';
}
