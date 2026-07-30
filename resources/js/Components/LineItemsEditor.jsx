import { Plus, Trash2 } from 'lucide-react';
import { FieldControl } from './FieldControl';

export default function LineItemsEditor({ fields, items, onChange, relationOptions }) {
    const rows = Array.isArray(items) && items.length ? items : [emptyItem(fields)];

    function update(id, key, value) {
        onChange(rows.map((item) => item.id === id ? { ...item, [key]: value } : item));
    }

    function add() {
        onChange([...rows, emptyItem(fields)]);
    }

    function remove(id) {
        if (rows.length <= 1) return;
        onChange(rows.filter((item) => item.id !== id));
    }

    return (
        <section className="line-items-editor">
            <div className="line-items-head">
                <div>
                    <span>规格明细</span>
                    <small>一张单据可填写多种材质和规格</small>
                </div>
                <button type="button" className="secondary-button line-items-add" onClick={add}>
                    <Plus size={14} aria-hidden="true" />
                    <span className="line-items-add-label">新增明细</span>
                </button>
            </div>
            <div className="line-item-list">
                {rows.map((item, index) => (
                    <fieldset key={item.id} aria-label={`明细 ${index + 1}`}>
                        <legend>明细 {index + 1}</legend>
                        <div className="line-item-fields">
                            {fields.filter(isEditable).map((field) => (
                                <label key={field.key}>
                                    <span>{field.label}{field.required && <b>*</b>}</span>
                                    <FieldControl
                                        field={field}
                                        value={item[field.key]}
                                        onChange={(key, value) => update(item.id, key, value)}
                                        relationOptions={relationOptions}
                                    />
                                </label>
                            ))}
                        </div>
                        <button
                            type="button"
                            className="icon-danger line-item-remove"
                            aria-label={`删除明细 ${index + 1}`}
                            disabled={rows.length <= 1}
                            onClick={() => remove(item.id)}
                        >
                            <Trash2 size={15} />
                        </button>
                    </fieldset>
                ))}
            </div>
        </section>
    );
}

export function emptyItem(fields) {
    return fields.reduce((item, field) => {
        if (isEditable(field)) item[field.key] = field.default ?? '';
        return item;
    }, { id: temporaryId() });
}

function isEditable(field) {
    return !['readonly', 'lookup', 'derived'].includes(field.type);
}

let sequence = 0;
function temporaryId() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    sequence += 1;
    return `new-item-${sequence}`;
}
