import { Link, router } from '@inertiajs/react';
import 'ag-grid-community/styles/ag-grid.css';
import 'ag-grid-community/styles/ag-theme-quartz.css';
import { CellStyleModule, ClientSideRowModelModule, ColumnApiModule, CustomEditorModule, DateFilterModule, NumberFilterModule, RowStyleModule, TextFilterModule } from 'ag-grid-community';
import { AgGridProvider, AgGridReact } from 'ag-grid-react';
import { Check, Eye, EyeOff, Pencil, Trash2, XCircle } from 'lucide-react';
import { useCallback, useMemo } from 'react';
import ComboBox from './ComboBox';
import { FieldControl } from './FieldControl';
import { columnOrderFromState, columnOrderState, columnOrderStorageKey } from './objectGridColumnState';

const modules = [CellStyleModule, ClientSideRowModelModule, ColumnApiModule, CustomEditorModule, DateFilterModule, NumberFilterModule, RowStyleModule, TextFilterModule];

export default function ObjectGrid({ object, records, fields, can, selectedRecordId, relationOptions, onRecordChange }) {
    const rowData = useMemo(() => records.map(rowFromRecord), [records]);
    const fieldKeys = useMemo(() => fields.map((field) => field.key), [fields]);
    const storageKey = useMemo(() => columnOrderStorageKey(object.key), [object.key]);
    const columnDefs = useMemo(() => [
        ...fields.map((field) => ({
            field: field.key,
            headerName: field.label,
            width: columnWidth(field, object.key, records),
            minWidth: 96,
            wrapHeaderText: true,
            autoHeaderHeight: true,
            editable: can.update && !['readonly', 'lookup', 'derived', 'file'].includes(field.type),
            cellEditor: GridEditor,
            cellEditorParams: { fieldConfig: field, relationOptions },
            cellEditorPopup: ['relation', 'select'].includes(field.type),
            cellEditorPopupPosition: 'under',
            valueGetter: (params) => rawValue(field, params.data),
            valueSetter: (params) => {
                if (field.system) return false;
                params.data[field.key] = params.newValue;
                return true;
            },
            cellRenderer: (params) => renderValue(field, params.data?.__record, params.value, relationOptions),
        })),
        {
            colId: 'actions',
            headerName: '',
            width: object.key === 'requisition' ? 204 : 132,
            pinned: 'right',
            sortable: false,
            filter: false,
            resizable: false,
            cellRenderer: (params) => params.data?.__record ? <GridActions object={object} record={params.data.__record} can={can} /> : null,
        },
    ], [can, fields, object, records, relationOptions]);

    const applySavedColumnOrder = useCallback((api) => {
        const saved = readColumnOrder(storageKey);
        if (saved.length) {
            api.applyColumnState({ state: columnOrderState(saved, fieldKeys), applyOrder: true });
        }
    }, [fieldKeys, storageKey]);

    const saveColumnOrder = useCallback((event) => {
        if (event.finished === false) return;
        const order = columnOrderFromState(event.api.getColumnState(), fieldKeys);
        if (order.length) {
            writeColumnOrder(storageKey, order);
        }
    }, [fieldKeys, storageKey]);

    async function saveCell(params) {
        const field = fields.find((item) => item.key === params.colDef.field);
        if (!field || params.newValue === params.oldValue) return;

        const previous = params.data.__record;
        const nextPayload = { ...previous.payload, [field.key]: params.newValue ?? '' };
        const optimistic = {
            ...previous,
            payload: nextPayload,
            display: { ...previous.display, [field.key]: displayValueFor(field, params.newValue, relationOptions) },
        };

        onRecordChange(optimistic);

        try {
            const response = await fetch(`/records/${previous.id}`, {
                method: 'PUT',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ payload: nextPayload }),
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                const firstError = Object.values(data.errors || {}).flat()[0];
                throw new Error(firstError || data.message || '保存失败');
            }

            const saved = data.record || optimistic;
            params.node.setData(rowFromRecord(saved));
            onRecordChange(saved);
        } catch (error) {
            params.node.setData(rowFromRecord(previous));
            onRecordChange(previous);
            window.alert(error.message || '保存失败，请重试。');
        }
    }

    return (
        <div className="object-grid ag-theme-quartz">
            <AgGridProvider modules={modules}>
                <AgGridReact
                    rowData={rowData}
                    columnDefs={columnDefs}
                    defaultColDef={{ resizable: true, sortable: true, filter: true, wrapHeaderText: true, autoHeaderHeight: true }}
                    getRowId={({ data }) => data.id}
                    maintainColumnOrder
                    onCellValueChanged={saveCell}
                    onColumnMoved={saveColumnOrder}
                    onGridReady={(event) => applySavedColumnOrder(event.api)}
                    rowClassRules={{ 'selected-row-grid': (params) => params.data?.id === selectedRecordId }}
                    stopEditingWhenCellsLoseFocus
                    theme="legacy"
                />
            </AgGridProvider>
        </div>
    );
}

function GridEditor({ value, onValueChange, stopEditing, fieldConfig, relationOptions }) {
    if (['relation', 'select'].includes(fieldConfig.type)) {
        const options = optionsFor(fieldConfig, value, relationOptions);

        return (
            <div className="grid-combo-editor">
                <ComboBox
                    value={value ?? ''}
                    options={options}
                    onChange={(next) => {
                        onValueChange(next);
                        setTimeout(() => stopEditing(), 0);
                    }}
                    autoFocus
                    startOpen
                />
            </div>
        );
    }

    return (
        <div className="grid-inline-editor" onKeyDown={(event) => {
            if (event.key === 'Enter') stopEditing();
        }}>
            <FieldControl
                field={fieldConfig}
                value={value ?? ''}
                onChange={(_, next) => onValueChange(next)}
                relationOptions={relationOptions}
                autoFocus
            />
        </div>
    );
}

function GridActions({ object, record, can }) {
    function approve() {
        router.post(`/requests/${record.id}/approve`, {}, { preserveScroll: true });
    }

    function reject() {
        router.post(`/requests/${record.id}/reject`, {}, { preserveScroll: true });
    }

    function destroy() {
        if (window.confirm(`删除 ${record.code}？`)) {
            router.delete(`/records/${record.id}`, { preserveScroll: true });
        }
    }

    return (
        <div className="grid-actions">
            <Link className="icon-link" href={`/objects/${object.key}?record=${record.id}&mode=detail`} preserveScroll title="详情">
                <Eye size={15} />
            </Link>
            {can.update ? (
                <Link className="icon-link" href={`/objects/${object.key}?record=${record.id}&mode=edit`} preserveScroll title="编辑">
                    <Pencil size={15} />
                </Link>
            ) : <span title="无编辑权限"><EyeOff size={15} /></span>}
            {object.key === 'requisition' && can.update && record.payload?.status === '待处理' && (
                <>
                    <button type="button" className="icon-success" onClick={approve} title="通过">
                        <Check size={15} />
                    </button>
                    <button type="button" className="icon-warning" onClick={reject} title="驳回">
                        <XCircle size={15} />
                    </button>
                </>
            )}
            {can.delete && <button type="button" className="icon-danger" onClick={destroy}><Trash2 size={15} /></button>}
        </div>
    );
}

function rowFromRecord(record) {
    return { id: record.id, __record: record, _code: record.code, _title: record.title, ...record.payload };
}

function rawValue(field, row) {
    if (!row) return '';
    if (field.system === 'code') return row._code;
    if (field.system === 'title') return row._title;

    return row[field.key];
}

function optionsFor(field, value, relationOptions) {
    if (field.type === 'relation') {
        const items = relationOptions[field.key]?.items || [];
        const hasValue = value && items.some((item) => item.id === value);

        return [
            { value: '', label: '未选择' },
            ...(value && !hasValue ? [{ value, label: '当前关联不可用' }] : []),
            ...items.map((item) => ({ value: item.id, label: item.label })),
        ];
    }

    return [
        { value: '', label: '未选择' },
        ...(field.options || []).map((option) => ({ value: option, label: option })),
    ];
}

function renderValue(field, record, value, relationOptions) {
    if (!value) return '';
    if (field.system === 'code') return <span className="mono">{value}</span>;
    if (field.system === 'title') return <span title={String(value)}>{String(value)}</span>;
    if (field.type === 'relation') {
        const text = record?.display?.[field.key] || displayValueFor(field, value, relationOptions);
        return <span title={text}>{text}</span>;
    }
    if (field.type === 'file') return <a className="relation-chip" href={value} target="_blank" rel="noreferrer">查看附件</a>;

    return <span title={String(value)}>{String(value)}</span>;
}

function displayValueFor(field, value, relationOptions) {
    if (field.type !== 'relation') return value;

    const option = relationOptions[field.key]?.items?.find((item) => item.id === value);

    return option?.label ?? (value ? '保存中...' : '');
}

function columnWidth(field, objectKey, records) {
    const base = {
        date: 112,
        number: 112,
        select: 132,
        relation: 220,
        text: 150,
        readonly: 150,
    }[field.type] ?? 150;
    const longest = Math.max(
        textWidth(field.label),
        ...records.slice(0, 30).map((record) => textWidth(displayText(field, record))),
    );
    const preferred = Math.max(base, longest + 34);
    const max = ['remark', 'risk'].includes(field.key) ? 360 : 300;

    if (objectKey === 'purchase' && ['date', 'purchase_date', 'expected_arrival_date', 'actual_arrival_date'].includes(field.key)) {
        return 118;
    }

    return Math.min(preferred, max);
}

function displayText(field, record) {
    if (field.system === 'code') return record.code;
    if (field.system === 'title') return record.title;
    if (field.type === 'relation') return record.display?.[field.key] || '';

    return record.display?.[field.key] ?? record.payload?.[field.key] ?? '';
}

function textWidth(value) {
    return Array.from(String(value ?? '')).reduce((sum, char) => sum + (char.charCodeAt(0) > 255 ? 13 : 7), 0);
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function readColumnOrder(key) {
    try {
        const value = window.localStorage.getItem(key);
        const parsed = value ? JSON.parse(value) : [];

        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function writeColumnOrder(key, order) {
    try {
        window.localStorage.setItem(key, JSON.stringify(order));
    } catch {
        // localStorage can be unavailable in private or locked-down browser modes.
    }
}
