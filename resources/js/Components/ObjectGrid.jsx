import { Link, router } from '@inertiajs/react';
import 'ag-grid-community/styles/ag-grid.css';
import 'ag-grid-community/styles/ag-theme-quartz.css';
import { CellSpanModule, CellStyleModule, ClientSideRowModelApiModule, ClientSideRowModelModule, ColumnApiModule, CustomEditorModule, DateFilterModule, LocaleModule, NumberFilterModule, RowApiModule, RowStyleModule, TextFilterModule } from 'ag-grid-community';
import { AgGridProvider, AgGridReact } from 'ag-grid-react';
import { Check, Download, Eye, EyeOff, Pencil, Trash2, XCircle } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { businessText } from '../businessLanguage';
import ComboBox from './ComboBox';
import CustomerContactCell from './CustomerContactCell';
import { FieldControl } from './FieldControl';
import { columnOrderFromState } from './objectGridColumnState';
import { expandObjectRecords, isItemField, rawRowValue, sameRecordSpan, scopedRelationOptions, updateRecordField } from './objectGridRows';

const modules = [CellSpanModule, CellStyleModule, ClientSideRowModelApiModule, ClientSideRowModelModule, ColumnApiModule, CustomEditorModule, DateFilterModule, LocaleModule, NumberFilterModule, RowApiModule, RowStyleModule, TextFilterModule];

export default function ObjectGrid({ object, records, fields, can, contactCan = {}, selectedRecordId, relationOptions, onRecordChange, onColumnOrderChange, onContactDetail, onContactCreate, exportUrl }) {
    const [gridApi, setGridApi] = useState(null);
    const [saveState, setSaveState] = useState({
        status: 'idle',
        message: can.update ? '双击单元格可编辑，修改后会自动保存' : '当前为只读视图',
    });
    const rowData = useMemo(() => expandObjectRecords(records, fields), [fields, records]);
    const fieldKeys = useMemo(() => fields.map((field) => field.key), [fields]);
    const columnDefs = useMemo(() => {
        const dataColumns = fields.map((field) => ({
            field: field.key,
            headerName: field.label,
            width: columnWidth(field, object.key, rowData, relationOptions),
            minWidth: 96,
            wrapHeaderText: true,
            autoHeaderHeight: true,
            sortable: false,
            filter: false,
            spanRows: isItemField(field) ? false : sameRecordSpan,
            editable: can.update && !field.readonly && !['readonly', 'lookup', 'derived', 'file'].includes(field.type),
            cellEditor: GridEditor,
            cellEditorParams: (params) => ({
                fieldConfig: field,
                relationOptions: scopedRelationOptions(
                    relationOptions,
                    params.data?.__record?.payload,
                    params.data?.__record?.display,
                    params.data?.__record?.id,
                ),
            }),
            cellEditorPopup: ['relation', 'select'].includes(field.type),
            cellEditorPopupPosition: 'under',
            valueGetter: (params) => rawRowValue(field, params.data),
            valueSetter: (params) => {
                if (field.system || field.readonly) return false;
                params.data[field.key] = params.newValue;
                return true;
            },
            cellRenderer: (params) => renderValue(field, params.data?.__record, params.value, relationOptions, params.data),
        }));

        if (object.key === 'customer') {
            const nameIndex = fields.findIndex((field) => field.key === 'name');
            dataColumns.splice(nameIndex >= 0 ? nameIndex + 1 : dataColumns.length, 0, {
                colId: 'customer_contacts',
                headerName: '联系人列表',
                width: 360,
                minWidth: 300,
                wrapText: true,
                sortable: false,
                filter: false,
                editable: false,
                cellRenderer: (params) => {
                    const record = params.data?.__record;
                    if (!record) return null;

                    return (
                        <CustomerContactCell
                            contacts={record.contacts || []}
                            canCreate={Boolean(contactCan.create)}
                            onDetail={(contact) => onContactDetail?.(contact, record)}
                            onCreate={() => onContactCreate?.(record)}
                        />
                    );
                },
            });
        }

        return [...dataColumns, {
            colId: 'actions',
            headerName: '操作',
            width: object.key === 'requisition' ? 440 : can.delete ? 286 : 210,
            pinned: 'right',
            sortable: false,
            filter: false,
            resizable: false,
            spanRows: sameRecordSpan,
            cellRenderer: (params) => params.data?.__record ? <GridActions object={object} record={params.data.__record} can={can} /> : null,
        }];
    }, [can, contactCan.create, fields, object, onContactCreate, onContactDetail, relationOptions, rowData]);

    const saveColumnOrder = useCallback((event) => {
        if (event.finished === false) return;
        const order = columnOrderFromState(event.api.getColumnState(), fieldKeys);
        if (order.length) {
            onColumnOrderChange?.(order);
        }
    }, [fieldKeys, onColumnOrderChange]);

    useEffect(() => {
        if (!gridApi || object.key !== 'customer') return;

        gridApi.forEachNode((node) => {
            node.setRowHeight(customerRowHeight(object.key, node.data?.__record, contactCan));
        });
        gridApi.onRowHeightChanged();
    }, [contactCan.create, gridApi, object.key, rowData]);

    async function saveCell(params) {
        const field = fields.find((item) => item.key === params.colDef.field);
        if (!field || params.newValue === params.oldValue) return;

        const previous = params.data.__record;
        const nextValue = params.newValue ?? '';
        const optimisticBase = updateRecordField(previous, field, nextValue, params.data.__itemId);
        const optimistic = isItemField(field) ? optimisticBase : {
            ...optimisticBase,
            display: { ...previous.display, [field.key]: displayValueFor(field, nextValue, relationOptions) },
        };
        const nextPayload = optimistic.payload;

        onRecordChange(optimistic);
        setSaveState({ status: 'saving', message: `正在保存“${field.label}”…` });

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
            onRecordChange(saved);
            if (data.status && data.status.includes('不一致')) {
                setSaveState({ status: 'warning', message: data.status });
            } else {
                setSaveState({ status: 'saved', message: `“${field.label}”已保存` });
            }
        } catch (error) {
            onRecordChange(previous);
            setSaveState({
                status: 'error',
                message: `保存失败：${error.message || '请重试'}`,
            });
        }
    }

    return (
        <div className="object-grid-wrap">
            <div className="object-grid-toolbar">
                <div className={`grid-save-state ${saveState.status}`} role="status" aria-live="polite">
                    {saveState.message}
                </div>
                <div className="object-grid-toolbar-actions">
                    <span className="grid-field-count">
                        已平铺全部 {fields.length} 个字段，可左右滚动查看
                    </span>
                    <a className="small-action" href={exportUrl || `/objects/${object.key}/export.csv`} download>
                        <Download size={14} /> 导出明细
                    </a>
                </div>
            </div>
            <div className="object-grid ag-theme-quartz">
                <AgGridProvider modules={modules}>
                    <AgGridReact
                        rowData={rowData}
                        columnDefs={columnDefs}
                        defaultColDef={{ resizable: true, sortable: false, filter: false, wrapHeaderText: true, autoHeaderHeight: true }}
                        localeText={{ noRowsToShow: `暂无${businessText(object.label)}记录` }}
                        noRowsOverlayComponent={GridEmptyState}
                        noRowsOverlayComponentParams={{ objectLabel: businessText(object.label), canCreate: can.create }}
                        getRowId={({ data }) => data.id}
                        getRowHeight={({ data }) => customerRowHeight(object.key, data?.__record, contactCan)}
                        enableCellSpan
                        maintainColumnOrder
                        onGridReady={(event) => setGridApi(event.api)}
                        onCellValueChanged={saveCell}
                        onColumnMoved={saveColumnOrder}
                        rowClassRules={{ 'selected-row-grid': (params) => params.data?.__recordId === selectedRecordId }}
                        stopEditingWhenCellsLoseFocus
                        theme="legacy"
                    />
                </AgGridProvider>
            </div>
        </div>
    );
}

function customerRowHeight(objectKey, record, contactCan) {
    if (objectKey !== 'customer' || !record) return undefined;

    const contactCount = Array.isArray(record.contacts) ? record.contacts.length : 0;
    if (contactCount === 0) return contactCan.create ? 70 : 56;

    const contactRowsHeight = contactCount * 42;
    const contactGapsHeight = Math.max(0, contactCount - 1) * 5;
    const createActionHeight = contactCan.create ? 30 : 0;

    return 20 + contactRowsHeight + contactGapsHeight + createActionHeight;
}

function GridEmptyState({ objectLabel, canCreate }) {
    return (
        <div className="grid-empty-state" role="status">
            <strong>暂无{objectLabel}记录</strong>
            <span>{canCreate ? '点击右上角“新建”开始录入' : '当前没有可查看的数据'}</span>
        </div>
    );
}

function GridEditor({ value, onValueChange, stopEditing, fieldConfig, relationOptions }) {
    if (['relation', 'select'].includes(fieldConfig.type)) {
        const options = optionsFor(fieldConfig, value, relationOptions);
        const relation = relationOptions[fieldConfig.key] || {};

        return (
            <div className="grid-combo-editor">
                <ComboBox
                    value={value ?? ''}
                    options={options}
                    searchUrl={fieldConfig.type === 'relation' ? relation.search_url : ''}
                    searchContext={relation.search_context}
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
                onChange={(_, next) => {
                    onValueChange(next);
                    if (fieldConfig.type === 'date') {
                        setTimeout(() => stopEditing(), 0);
                    }
                }}
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
        const label = [record.code, record.title].filter(Boolean).join(' · ');
        if (window.confirm(`确定删除“${label}”吗？\n\n删除后无法恢复。`)) {
            router.delete(`/records/${record.id}`, { preserveScroll: true });
        }
    }

    return (
        <div className="grid-actions">
            {record.is_new_task && (
                <span className="workflow-task-badge" aria-label="新任务">新任务</span>
            )}
            <Link
                className="grid-action"
                href={`/objects/${object.key}?record=${record.id}&mode=detail`}
                preserveScroll
                aria-label={`查看 ${record.code} 详情`}
            >
                <Eye size={14} />
                <span>查看</span>
            </Link>
            {can.update ? (
                <Link
                    className="grid-action"
                    href={`/objects/${object.key}?record=${record.id}&mode=edit`}
                    preserveScroll
                    aria-label={`编辑 ${record.code}`}
                >
                    <Pencil size={14} />
                    <span>编辑</span>
                </Link>
            ) : <span className="grid-action-disabled" title="当前角色无编辑权限"><EyeOff size={14} />只读</span>}
            {object.key === 'requisition' && can.update && record.payload?.status === '待处理' && (
                <>
                    <button type="button" className="grid-action success" onClick={approve} aria-label={`通过 ${record.code}`}>
                        <Check size={14} />
                        <span>通过</span>
                    </button>
                    <button type="button" className="grid-action warning" onClick={reject} aria-label={`驳回 ${record.code}`}>
                        <XCircle size={14} />
                        <span>驳回</span>
                    </button>
                </>
            )}
            {can.delete && (
                <button type="button" className="grid-action danger" onClick={destroy} aria-label={`删除 ${record.code}`}>
                    <Trash2 size={14} />
                    <span>删除</span>
                </button>
            )}
        </div>
    );
}

function optionsFor(field, value, relationOptions) {
    if (['relation', 'creatable_relation'].includes(field.type)) {
        const relation = relationOptions[field.key] || {};
        const items = relation.items || [];
        const hasValue = value && items.some((item) => item.id === value);
        const historical = value && !hasValue
            ? (relation.selectedItems || []).find((item) => item.id === value)
            : null;

        return [
            { value: '', label: '未选择' },
            ...(value && !hasValue ? [{
                value,
                label: historical ? `${historical.label}（历史关联）` : '当前关联不可用',
            }] : []),
            ...items.map((item) => ({ value: item.id, label: item.label })),
        ];
    }

    return [
        { value: '', label: '未选择' },
        ...(field.options || []).map((option) => ({ value: option, label: option })),
    ];
}

function renderValue(field, record, value, relationOptions, row = null) {
    if (!value) return '';
    if (field.system === 'code') return <span className="mono">{value}</span>;
    if (field.system === 'title') return <span title={String(value)}>{String(value)}</span>;
    if (['relation', 'creatable_relation'].includes(field.type)) {
        const snapshot = isItemField(field) ? row?._snapshots?.[field.key] : null;
        const text = (snapshot?.id === value ? snapshot.label : null)
            || (isItemField(field) ? null : record?.display?.[field.key])
            || displayValueFor(field, value, relationOptions);
        return <span title={text}>{text}</span>;
    }
    if (field.type === 'multirelation') {
        const text = Array.isArray(record?.display?.[field.key]) ? record.display[field.key].join('、') : '';
        return <span title={text}>{text}</span>;
    }
    if (field.type === 'file') return <a className="relation-chip" href={record?.display?.[field.key] || value} target="_blank" rel="noreferrer">查看附件</a>;

    return <span title={String(value)}>{String(value)}</span>;
}

function displayValueFor(field, value, relationOptions) {
    if (!['relation', 'creatable_relation'].includes(field.type)) return value;

    const option = relationOptions[field.key]?.items?.find((item) => item.id === value);

    return option?.label ?? (value ? '保存中...' : '');
}

function columnWidth(field, objectKey, rows, relationOptions) {
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
        ...rows.map((row) => textWidth(displayText(field, row, relationOptions))),
    );
    const preferred = Math.max(base, longest + 34);
    const max = ['remark', 'risk'].includes(field.key) ? 420 : 320;

    if (objectKey === 'purchase' && ['date', 'purchase_date', 'expected_arrival_date', 'actual_arrival_date'].includes(field.key)) {
        return 118;
    }

    return Math.min(preferred, max);
}

function displayText(field, row, relationOptions) {
    if (field.system === 'code') return row._code;
    if (field.system === 'title') return row._title;
    if (['relation', 'creatable_relation'].includes(field.type)) {
        const snapshot = isItemField(field) ? row?._snapshots?.[field.key] : null;
        if (snapshot && snapshot.id === row[field.key] && snapshot.label) return snapshot.label;
        return displayValueFor(field, row[field.key], relationOptions);
    }

    return row[field.key] ?? '';
}

function textWidth(value) {
    return Array.from(String(value ?? '')).reduce((sum, char) => sum + (char.charCodeAt(0) > 255 ? 13 : 7), 0);
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}
