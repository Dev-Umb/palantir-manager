import { Link, router } from '@inertiajs/react';
import 'ag-grid-community/styles/ag-grid.css';
import 'ag-grid-community/styles/ag-theme-quartz.css';
import { CellSpanModule, CellStyleModule, ClientSideRowModelApiModule, ClientSideRowModelModule, ColumnApiModule, CustomEditorModule, DateFilterModule, LocaleModule, NumberFilterModule, RowApiModule, RowStyleModule, ScrollApiModule, TextFilterModule, TooltipModule } from 'ag-grid-community';
import { AgGridProvider, AgGridReact } from 'ag-grid-react';
import { Check, Eye, EyeOff, Pencil, Trash2, XCircle } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { businessText } from '../businessLanguage';
import ComboBox from './ComboBox';
import CustomerContactCell from './CustomerContactCell';
import FeedbackDialog from './FeedbackDialog';
import { FieldControl } from './FieldControl';
import MultiComboBox from './MultiComboBox';
import RowActions from './RowActions';
import { columnOrderFromState, columnWidthsFromState } from './objectGridColumnState';
import { formatObjectNumber } from './objectNumberFormatting';
import { expandObjectRecords, isItemField, rawRowValue, sameRecordSpan, scopedRelationOptions, updateRecordField } from './objectGridRows';

const modules = [CellSpanModule, CellStyleModule, ClientSideRowModelApiModule, ClientSideRowModelModule, ColumnApiModule, CustomEditorModule, DateFilterModule, LocaleModule, NumberFilterModule, RowApiModule, RowStyleModule, ScrollApiModule, TextFilterModule, TooltipModule];
export const MIN_DATA_COLUMN_WIDTH = 72;

export default function ObjectGrid({
    object,
    records,
    subtotal = null,
    fields,
    can,
    selectedRecordId,
    recordListHref = null,
    relationOptions,
    savedColumnWidths = {},
    onRecordChange,
    columnOrderLocked = false,
    onColumnOrderChange,
    onColumnWidthsChange,
    onContactOpen,
    onContactCreate,
    canCreateContact = false,
}) {
    const [visibleFieldCount, setVisibleFieldCount] = useState(fields.length);
    const [saveState, setSaveState] = useState({
        status: 'idle',
        message: can.update ? '双击有编辑权限的单元格可修改，修改后会自动保存' : '当前为只读视图',
    });
    const [feedback, setFeedback] = useState(null);
    const subtotalLabelField = useMemo(() => fields[0]?.key || null, [fields]);
    const rowData = useMemo(() => {
        const dataRows = expandObjectRecords(records, fields);

        return subtotal ? [...dataRows, subtotalRow(subtotal)] : dataRows;
    }, [fields, records, subtotal]);
    const fieldKeys = useMemo(() => fields.map((field) => field.key), [fields]);
    const destroyRecord = useCallback(async (record) => {
        try {
            const response = await fetch(`/records/${record.id}`, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(firstResponseError(data, '删除失败，请重试。'));
            }

            setSaveState({ status: 'saved', message: data.status || `${businessText(object.label)}已删除` });
            router.reload({
                only: ['records', 'selectedRecord'],
                preserveScroll: true,
            });
        } catch (error) {
            setFeedback({
                title: `无法删除${businessText(object.label)}`,
                messages: [error.message || '删除失败，请重试。'],
            });
        }
    }, [object.label]);
    const columnDefs = useMemo(() => {
        const dataColumns = fields.map((field) => {
            const bounds = columnBounds(field);
            const subtotalWidth = subtotalColumnWidth(field, subtotal?.values?.[field.key], field.key === subtotalLabelField);

            return {
                field: field.key,
                headerName: field.label,
                width: Math.max(savedColumnWidths[field.key] ?? columnWidth(field, object.key, rowData, relationOptions), subtotalWidth),
                minWidth: Math.max(bounds.min, subtotalWidth),
                maxWidth: Math.max(bounds.max, subtotalWidth),
                wrapHeaderText: true,
                autoHeaderHeight: true,
                sortable: false,
                filter: false,
                cellClass: numericField(field) ? 'numeric-cell' : undefined,
                spanRows: isItemField(field) ? false : sameRecordSpan,
                editable: (params) => !params.data?.__subtotal
                    && can.update
                    && params.data?.__record?.can_update !== false
                    && !field.readonly
                    && fieldEditableForRecord(field, params.data?.__record)
                    && !(object.key === 'customer' && field.key === 'cooperation_history')
                    && !['readonly', 'lookup', 'derived', 'file', 'files'].includes(field.type),
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
                cellEditorPopup: ['relation', 'select', 'account'].includes(field.type),
                cellEditorPopupPosition: 'under',
                valueGetter: (params) => rawRowValue(field, params.data),
                valueSetter: (params) => {
                    if (field.system || field.readonly) return false;
                    params.data[field.key] = params.newValue;
                    return true;
                },
                cellRenderer: (params) => {
                    if (params.data?.__subtotal) {
                        return <SubtotalCell field={field} value={params.value} showLabel={field.key === subtotalLabelField} />;
                    }

                    return object.key === 'customer' && field.key === 'cooperation_history'
                        ? <CooperationHistoryCell projects={params.data?.__record?.cooperation_projects || []} />
                        : renderValue(object, field, params.data?.__record, params.value, relationOptions, params.data);
                },
            };
        });

        if (object.key === 'customer') {
            const nameIndex = fields.findIndex((field) => field.key === 'name');
            dataColumns.splice(nameIndex >= 0 ? nameIndex + 1 : dataColumns.length, 0, {
                colId: 'customer_contacts',
                headerName: '联系人列表',
                width: 300,
                minWidth: 240,
                maxWidth: 360,
                sortable: false,
                filter: false,
                editable: false,
                cellRenderer: (params) => {
                    const record = params.data?.__record;
                    if (!record) return null;

                    return (
                        <CustomerContactCell
                            contacts={record.contacts || []}
                            customerName={record.title || '当前客户'}
                            canCreate={canCreateContact}
                            onOpen={() => onContactOpen?.(record)}
                            onCreate={() => onContactCreate?.(record)}
                        />
                    );
                },
            });
        }

        return [...dataColumns, {
            colId: 'actions',
            headerName: '操作',
            width: 124,
            minWidth: 124,
            maxWidth: 124,
            pinned: 'right',
            sortable: false,
            filter: false,
            resizable: false,
            spanRows: sameRecordSpan,
            cellClass: 'action-cell',
            cellRenderer: (params) => params.data?.__record
                ? <GridActions object={object} record={params.data.__record} can={can} onDelete={destroyRecord} recordListHref={recordListHref} />
                : null,
        }];
    }, [can, canCreateContact, destroyRecord, fields, object, onContactCreate, onContactOpen, recordListHref, relationOptions, rowData, savedColumnWidths, subtotal, subtotalLabelField]);

    const saveColumnOrder = useCallback((event) => {
        if (event.finished === false) return;
        const order = columnOrderFromState(event.api.getColumnState(), fieldKeys);
        if (order.length) {
            onColumnOrderChange?.(order);
        }
    }, [fieldKeys, onColumnOrderChange]);

    const updateVisibleFieldCount = useCallback((api) => {
        const horizontalRange = api?.getHorizontalPixelRange?.();
        const displayedColumns = api?.getAllDisplayedColumns?.() || [];
        const currentFields = new Set(fieldKeys);
        const visible = displayedColumns.filter((column) => {
            if (!currentFields.has(column.getColId())) return false;
            if (!horizontalRange) return true;

            const left = column.getLeft?.() ?? 0;
            const right = left + (column.getActualWidth?.() ?? 0);

            return right > horizontalRange.left && left < horizontalRange.right;
        }).length;

        setVisibleFieldCount(visible);
    }, [fieldKeys]);

    const saveColumnWidths = useCallback((event) => {
        updateVisibleFieldCount(event.api);
        if (event.finished === false || event.source !== 'uiColumnResized') return;

        onColumnWidthsChange?.(columnWidthsFromState(event.api.getColumnState(), fieldKeys));
    }, [fieldKeys, onColumnWidthsChange, updateVisibleFieldCount]);

    useEffect(() => {
        setVisibleFieldCount(fields.length);
    }, [fields.length, object.key]);

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
                status: 'idle',
                message: can.update ? '双击有编辑权限的单元格可修改，修改后会自动保存' : '当前为只读视图',
            });
            setFeedback({
                title: `“${field.label}”保存失败`,
                messages: [error.message || '保存失败，请重试。'],
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
                        共 {fields.length} 个字段，当前可见 {visibleFieldCount} 列，可横向滚动查看全部字段
                    </span>
                </div>
            </div>
            <div className={`object-grid ag-theme-quartz${rowData.length > 7 ? ' has-zebra' : ''}`}>
                <AgGridProvider modules={modules}>
                    <AgGridReact
                        rowData={rowData}
                        columnDefs={columnDefs}
                        defaultColDef={{ resizable: true, sortable: false, filter: false, wrapHeaderText: true, autoHeaderHeight: true, headerComponent: GridHeader }}
                        localeText={{ noRowsToShow: `暂无${businessText(object.label)}记录` }}
                        noRowsOverlayComponent={GridEmptyState}
                        noRowsOverlayComponentParams={{ objectLabel: businessText(object.label), canCreate: can.create }}
                        getRowId={({ data }) => data.id}
                        getRowHeight={({ data }) => data?.__subtotal ? 56 : 44}
                        enableCellSpan
                        maintainColumnOrder
                        suppressMovableColumns={columnOrderLocked}
                        alwaysShowHorizontalScroll
                        onGridReady={(event) => {
                            setTimeout(() => updateVisibleFieldCount(event.api), 0);
                        }}
                        onCellValueChanged={saveCell}
                        onColumnMoved={columnOrderLocked ? undefined : saveColumnOrder}
                        onColumnResized={saveColumnWidths}
                        onBodyScroll={(event) => updateVisibleFieldCount(event.api)}
                        onGridSizeChanged={(event) => updateVisibleFieldCount(event.api)}
                        rowClassRules={{
                            'selected-row-grid': (params) => params.data?.__recordId === selectedRecordId,
                            'subtotal-row-grid': (params) => params.data?.__subtotal === true,
                        }}
                        stopEditingWhenCellsLoseFocus
                        theme="legacy"
                    />
                </AgGridProvider>
            </div>
            <FeedbackDialog
                title={feedback?.title}
                messages={feedback?.messages}
                onClose={() => setFeedback(null)}
            />
        </div>
    );
}

function subtotalRow(subtotal) {
    return {
        id: '__filtered-subtotal__',
        __subtotal: true,
        __subtotalValues: subtotal.values || {},
    };
}

function SubtotalCell({ field, value, showLabel }) {
    const total = field.type === 'number' ? formatSubtotalValue(value) : null;

    if (showLabel && total !== null) {
        return <span className="subtotal-cell"><b>小计</b><span>{total}</span></span>;
    }
    if (showLabel) {
        return <b className="subtotal-label">小计</b>;
    }

    return total === null ? null : <strong className="subtotal-value">{total}</strong>;
}

export function formatSubtotalValue(value) {
    if (!Number.isFinite(Number(value))) return null;

    return Number(value).toLocaleString('zh-CN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

export function subtotalColumnWidth(field, value, showLabel = false) {
    if (field.type !== 'number') return 0;

    const formatted = formatSubtotalValue(value);
    if (formatted === null) return 0;

    return Math.min(280, textWidth(formatted) + 60 + (showLabel ? 44 : 0));
}

function GridHeader({ displayName }) {
    return <span className="grid-header-label" title={displayName}>{displayName}</span>;
}

function GridEmptyState({ objectLabel, canCreate }) {
    return (
        <div className="grid-empty-state" role="status">
            <strong>暂无{objectLabel}记录</strong>
            <span>{canCreate ? '点击右上角“新建”开始录入' : '当前没有可查看的数据'}</span>
        </div>
    );
}

function CooperationHistoryCell({ projects }) {
    if (!projects.length) return <span className="empty-value">暂无关联项目</span>;

    const first = projects[0];
    const summary = [first.code, first.title, first.date].filter(Boolean).join(' · ');

    return (
        <span className="cooperation-history-cell" title={projects.map((project) => (
            [project.code, project.title, project.date].filter(Boolean).join(' · ')
        )).join('\n')}>
            <span>{summary}</span>
            {projects.length > 1 && <b>+{projects.length - 1}</b>}
        </span>
    );
}

function GridEditor({ value, onValueChange, stopEditing, fieldConfig, relationOptions }) {
    if (['multirelation', 'multiaccount'].includes(fieldConfig.type)) {
        const relation = relationOptions[fieldConfig.key] || {};

        return (
            <div className="grid-multi-combo-editor">
                <MultiComboBox
                    value={Array.isArray(value) ? value : []}
                    items={relation.items || []}
                    selectedItems={relation.selectedItems || []}
                    searchUrl={relation.search_url}
                    searchContext={relation.search_context}
                    searchPlaceholder={fieldConfig.type === 'multiaccount' ? '输入业务员姓名' : undefined}
                    onChange={onValueChange}
                    onClose={() => setTimeout(() => stopEditing(), 0)}
                    startOpen
                />
            </div>
        );
    }

    if (['relation', 'select', 'account'].includes(fieldConfig.type)) {
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

function GridActions({ object, record, can, onDelete, recordListHref }) {
    const canUpdate = can.update && record.can_update !== false;
    function approve() {
        router.post(`/requests/${record.id}/approve`, {}, { preserveScroll: true });
    }

    function reject() {
        router.post(`/requests/${record.id}/reject`, {}, { preserveScroll: true });
    }

    function destroy() {
        const label = [record.code, record.title].filter(Boolean).join(' · ');
        if (window.confirm(`确定删除“${label}”吗？\n\n删除后无法恢复。`)) {
            onDelete?.(record);
        }
    }

    return (
        <div className="grid-actions">
            {record.is_new_task && (
                <span className="workflow-task-badge" aria-label="新任务">新任务</span>
            )}
            <RowActions
                menuLabel={`${record.code} 更多操作`}
                primary={(
                    <Link
                        className="grid-action"
                        href={objectRecordHref(object.key, record.id, 'detail', recordListHref)}
                        preserveScroll
                        aria-label={`查看 ${record.code} 详情`}
                    >
                        <Eye size={14} />
                        <span>查看</span>
                    </Link>
                )}
                secondary={[
                    canUpdate && (
                        <Link key="edit" href={objectRecordHref(object.key, record.id, 'edit', recordListHref)} preserveScroll aria-label={`编辑 ${record.code}`}>
                            <Pencil size={14} /> 编辑
                        </Link>
                    ),
                    object.key === 'requisition' && can.update && record.payload?.status === '待处理' && (
                        <button key="approve" type="button" onClick={approve} aria-label={`通过 ${record.code}`}>
                            <Check size={14} /> 通过
                        </button>
                    ),
                    object.key === 'requisition' && can.update && record.payload?.status === '待处理' && (
                        <button key="reject" type="button" className="danger" onClick={reject} aria-label={`驳回 ${record.code}`}>
                            <XCircle size={14} /> 驳回
                        </button>
                    ),
                    can.delete && (
                        <button key="delete" type="button" className="danger" onClick={destroy} aria-label={`删除 ${record.code}`}>
                            <Trash2 size={14} /> 删除
                        </button>
                    ),
                    !canUpdate && !can.delete && (
                        <span key="readonly" className="row-action-readonly"><EyeOff size={14} /> 只读</span>
                    ),
                ].filter(Boolean)}
            />
        </div>
    );
}

export function objectRecordHref(objectKey, recordId, mode, listHref = null) {
    const [path, query = ''] = (listHref || `/objects/${objectKey}`).split('?');
    const params = new URLSearchParams(query);
    params.set('record', recordId);
    params.set('mode', mode);

    return `${path}?${params.toString()}`;
}

function optionsFor(field, value, relationOptions) {
    if (field.type === 'account') {
        return [
            { value: '', label: '未选择' },
            ...(relationOptions[field.key]?.items || []).map((item) => ({ value: String(item.id), label: item.label })),
        ];
    }

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
        ...(field.options || [])
            .filter((option) => !(field.restricted_options || []).includes(option) || option === value)
            .map((option) => ({ value: option, label: option })),
    ];
}

function renderValue(object, field, record, value, relationOptions, row = null) {
    if (value === null || value === undefined || value === '') return <span className="empty-value">—</span>;
    if (object.key === 'project' && field.key === 'name' && record?.is_informed_project) {
        return (
            <span className="informed-project-cell" title={String(value)}>
                <b className="informed-project-badge">知会项目</b>
                <span>{String(value)}</span>
            </span>
        );
    }
    if (field.system === 'code') return <span className="mono">{value}</span>;
    if (field.system === 'title') return <span title={String(value)}>{String(value)}</span>;
    if (['relation', 'creatable_relation'].includes(field.type)) {
        const snapshot = isItemField(field) ? row?._snapshots?.[field.key] : null;
        const relationText = (snapshot?.id === value ? snapshot.label : null)
            || (isItemField(field) ? null : record?.display?.[field.key])
            || displayValueFor(field, value, relationOptions);
        const text = relationGridText(object.key, field, relationText);
        return text ? <span title={text}>{text}</span> : <span className="empty-value">—</span>;
    }
    if (['multirelation', 'multiaccount'].includes(field.type)) {
        const text = Array.isArray(record?.display?.[field.key]) ? record.display[field.key].join('、') : '';
        return text ? <span title={text}>{text}</span> : <span className="empty-value">—</span>;
    }
    if (field.type === 'file') return <a className="relation-chip" href={record?.display?.[field.key] || value} target="_blank" rel="noreferrer">查看附件</a>;
    if (field.type === 'files') {
        const attachments = Array.isArray(record?.display?.[field.key]) ? record.display[field.key] : [];
        return attachments.length
            ? <span title={`${attachments.length} 个附件`}>{attachments.length} 个附件</span>
            : <span className="empty-value">—</span>;
    }
    if (field.type === 'account') {
        return <span title={record?.display?.[field.key] || ''}>{record?.display?.[field.key] || '—'}</span>;
    }

    const formatted = formatObjectNumber(object.key, field, value);

    return <span title={String(formatted)}>{String(formatted)}</span>;
}

function displayValueFor(field, value, relationOptions) {
    if (field.type === 'multiaccount') {
        const labels = new Map((relationOptions[field.key]?.items || []).map((item) => [String(item.id), item.label]));

        return (Array.isArray(value) ? value : []).map((id) => labels.get(String(id)) || String(id));
    }
    if (field.type === 'account') {
        const option = relationOptions[field.key]?.items?.find((item) => String(item.id) === String(value));

        return option?.label ?? (value ? '保存中...' : '');
    }
    if (!['relation', 'creatable_relation'].includes(field.type)) return value;

    const option = relationOptions[field.key]?.items?.find((item) => item.id === value);

    return option?.label ?? (value ? '保存中...' : '');
}

export function fieldEditableForRecord(field, record) {
    const allowedStatuses = field.editable_when_status;

    return !Array.isArray(allowedStatuses) || allowedStatuses.includes(record?.payload?.status);
}

function columnWidth(field, objectKey, rows, relationOptions) {
    const bounds = columnBounds(field);
    const longest = Math.max(
        textWidth(field.label),
        ...rows.map((row) => textWidth(displayText(field, row, relationOptions, objectKey))),
    );
    const preferred = Math.max(bounds.preferred, longest + 34);

    if (objectKey === 'purchase' && ['date', 'purchase_date', 'expected_arrival_date', 'actual_arrival_date'].includes(field.key)) {
        return 118;
    }

    return Math.min(preferred, bounds.max);
}

export function columnBounds(field) {
    if (['relation', 'multirelation', 'multiaccount', 'creatable_relation'].includes(field.type)) {
        return { min: MIN_DATA_COLUMN_WIDTH, preferred: 280, max: 360 };
    }
    if (field.type === 'date') {
        return { min: MIN_DATA_COLUMN_WIDTH, preferred: 120, max: 132 };
    }
    if (numericField(field)) {
        return { min: MIN_DATA_COLUMN_WIDTH, preferred: 118, max: 160 };
    }
    if (field.type === 'select') {
        return { min: MIN_DATA_COLUMN_WIDTH, preferred: 132, max: 180 };
    }
    if (field.system === 'code') {
        return { min: MIN_DATA_COLUMN_WIDTH, preferred: 180, max: 220 };
    }
    if (['remark', 'risk'].includes(field.key)) {
        return { min: MIN_DATA_COLUMN_WIDTH, preferred: 240, max: 420 };
    }

    return { min: MIN_DATA_COLUMN_WIDTH, preferred: 200, max: 320 };
}

function numericField(field) {
    return field.type === 'number'
        || /(?:amount|count|price|qty|quantity|weight|progress|total|rate|percent)$/i.test(field.key);
}

function displayText(field, row, relationOptions, objectKey) {
    if (field.system === 'code') return row._code;
    if (field.system === 'title') return row._title;
    if (['relation', 'creatable_relation'].includes(field.type)) {
        const snapshot = isItemField(field) ? row?._snapshots?.[field.key] : null;
        const text = snapshot && snapshot.id === row[field.key] && snapshot.label
            ? snapshot.label
            : displayValueFor(field, row[field.key], relationOptions);

        return relationGridText(objectKey, field, text);
    }

    return formatObjectNumber(objectKey, field, row[field.key] ?? '');
}

function relationGridText(objectKey, field, text) {
    if (objectKey !== 'project' || field.key !== 'customer_id' || typeof text !== 'string') {
        return text;
    }

    const separator = text.indexOf(' · ');

    return separator >= 0 ? text.slice(separator + 3) : text;
}

function textWidth(value) {
    return Array.from(String(value ?? '')).reduce((sum, char) => sum + (char.charCodeAt(0) > 255 ? 13 : 7), 0);
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function firstResponseError(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback;
}
