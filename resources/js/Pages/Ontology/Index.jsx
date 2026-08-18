import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Download, Plus, RefreshCw, Search, SlidersHorizontal, Trash2, X } from 'lucide-react';
import { lazy, Suspense, useEffect, useMemo, useRef, useState } from 'react';
import Layout from '../../Components/Layout';
import CustomerContactModal from '../../Components/CustomerContactModal';
import ProjectCustomerInlineFields, {
    CustomerProfileConflictDialog,
    normalizedProjectCustomerProfile,
    previewProjectCustomerProfile,
    projectCustomerProfile,
} from '../../Components/ProjectCustomerInlineFields';
import { SchemaForm } from '../../Components/FieldControl';
import LineItemsEditor, { emptyItem } from '../../Components/LineItemsEditor';
import {
    columnOrderStorageKey,
    columnWidthStorageKey,
    fieldsInColumnOrder,
    readColumnOrder,
    readColumnWidths,
} from '../../Components/objectGridColumnState';
import { scopedRelationOptions } from '../../Components/objectGridRows';
import { businessText } from '../../businessLanguage';
import { formatObjectNumber } from '../../Components/objectNumberFormatting';

const ObjectGrid = lazy(() => import('../../Components/ObjectGrid'));
export const PAGE_SIZE_OPTIONS = Array.from({ length: 10 }, (_, index) => (index + 1) * 10);

export default function Index({ objects = [], contactObject = null, currentObject, records, subtotal = null, can, relationOptions, selectedRecordId, selectedRecord: selectedRecordProp = null, businessUsers = [] }) {
    const { auth } = usePage().props;
    const params = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search);
    const mode = params.get('mode');
    const [tableRecords, setTableRecords] = useState(records.data);
    const [contactModal, setContactModal] = useState(null);
    const selectedRecord = tableRecords.find((record) => record.id === selectedRecordId) || selectedRecordProp;
    const closeHref = objectListHref(currentObject.key, params);
    const hasFixedColumnOrder = currentObject.key === 'project';
    const storageKey = columnOrderStorageKey(auth.user?.id ?? 'anonymous', currentObject.key);
    const widthStorageKey = columnWidthStorageKey(auth.user?.id ?? 'anonymous', currentObject.key);
    const [columnOrder, setColumnOrder] = useState(() => hasFixedColumnOrder ? [] : readColumnOrder(storageKey));
    const [columnWidths, setColumnWidths] = useState(() => readColumnWidths(widthStorageKey));
    const orderedFields = useMemo(
        () => hasFixedColumnOrder ? currentObject.fields : fieldsInColumnOrder(currentObject.fields, columnOrder),
        [columnOrder, currentObject.fields, hasFixedColumnOrder],
    );
    const createForm = useForm({ payload: defaults(currentObject.fields, params) });
    const createSubmittingRef = useRef(false);
    const createCheckingCustomerRef = useRef(false);
    const [createCheckingCustomer, setCreateCheckingCustomer] = useState(false);
    const [createCustomerError, setCreateCustomerError] = useState('');
    const [createCustomerConflicts, setCreateCustomerConflicts] = useState([]);
    const contactCan = {
        create: (auth.permissions || []).includes('object.customer_contact.create'),
        update: (auth.permissions || []).includes('object.customer_contact.update'),
        delete: (auth.permissions || []).includes('object.customer_contact.delete'),
    };
    const exportUrl = exportUrlFor(currentObject.key, params);
    const objectLabel = businessText(currentObject.label);

    useEffect(() => {
        setTableRecords(records.data);
    }, [records.data]);

    useEffect(() => {
        setColumnOrder(hasFixedColumnOrder ? [] : readColumnOrder(storageKey));
    }, [hasFixedColumnOrder, storageKey]);

    useEffect(() => {
        setColumnWidths(readColumnWidths(widthStorageKey));
    }, [widthStorageKey]);

    function updateTableRecord(nextRecord) {
        setTableRecords((current) => current.map((record) => record.id === nextRecord.id ? nextRecord : record));
    }

    async function create(event) {
        event.preventDefault();
        await submitCreateProject(false, false);
    }

    async function submitCreateProject(overwriteConfirmed, skipPreview) {
        if (createSubmittingRef.current || createCheckingCustomerRef.current) return;

        const profile = createForm.data.payload.customer_profile;
        if (currentObject.key === 'project' && can.manage_customers && profile && !skipPreview) {
            createCheckingCustomerRef.current = true;
            setCreateCheckingCustomer(true);
            setCreateCustomerError('');
            try {
                const preview = await previewProjectCustomerProfile(profile);
                if (preview.conflicts?.length) {
                    setCreateCustomerConflicts(preview.conflicts);
                    return;
                }
            } catch (error) {
                setCreateCustomerError(error.message || '客户资料检查失败。');
                return;
            } finally {
                createCheckingCustomerRef.current = false;
                setCreateCheckingCustomer(false);
            }
        }

        createSubmittingRef.current = true;
        createForm.transform((data) => withCustomerProfile(data, profile, overwriteConfirmed));
        createForm.post(`/objects/${currentObject.id}`, {
            preserveScroll: true,
            onFinish: () => {
                createSubmittingRef.current = false;
            },
        });
    }

    function saveColumnOrder(order) {
        if (hasFixedColumnOrder) return;

        const normalized = fieldsInColumnOrder(currentObject.fields, order).map((field) => field.key);
        setColumnOrder(normalized);
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(normalized));
        } catch {
            // localStorage can be unavailable in private or locked-down browser modes.
        }
    }

    function saveColumnWidths(widths) {
        setColumnWidths(widths);
        try {
            window.localStorage.setItem(widthStorageKey, JSON.stringify(widths));
        } catch {
            // localStorage can be unavailable in private or locked-down browser modes.
        }
    }

    function openContactDetail(contact, customer) {
        setContactModal({ mode: 'detail', contact, customer });
    }

    function openContactList(customer) {
        setContactModal({ mode: 'list', contact: null, contacts: customer.contacts || [], customer });
    }

    function openContactCreate(customer) {
        if (!contactObject) return;
        setContactModal({ mode: 'create', contact: null, customer });
    }

    function contactSaved() {
        setContactModal(null);
        router.reload({
            only: ['records', 'selectedRecord'],
            preserveScroll: true,
        });
    }

    function contactDeleted() {
        setContactModal(null);
        router.reload({
            only: ['records', 'selectedRecord'],
            preserveScroll: true,
        });
    }

    return (
        <Layout
            title={objectLabel}
            eyebrow="业务资料"
            hideHeader
        >
            <Head title={objectLabel} />
            <div className="object-main">
                    <ObjectSwitcher objects={objects} currentObject={currentObject} />
                    <div className="surface object-list-surface">
                        <div className="object-toolbar">
                            <ObjectListControls
                                objectKey={currentObject.key}
                                params={params}
                                records={records}
                                fields={currentObject.fields}
                                relationOptions={relationOptions}
                                actions={(
                                    <div className="object-toolbar-actions">
                                        <a className="secondary-button" href={exportUrl} download>
                                            <Download size={15} /> 导出
                                        </a>
                                        {can.create && (
                                            <Link className="small-action action-button" href={`/objects/${currentObject.key}?mode=create`}>
                                                <Plus size={15} /> 新建
                                            </Link>
                                        )}
                                    </div>
                                )}
                            />
                        </div>
                        <Suspense fallback={<div className="table-loading">列表加载中...</div>}>
                            <ObjectGrid
                                key={storageKey}
                                object={currentObject}
                                records={tableRecords}
                                subtotal={subtotal}
                                fields={orderedFields}
                                can={can}
                                selectedRecordId={selectedRecordId}
                                recordListHref={recordListHrefForObject(currentObject.key, params)}
                                relationOptions={relationOptions}
                                savedColumnWidths={columnWidths}
                                onRecordChange={updateTableRecord}
                                columnOrderLocked={hasFixedColumnOrder}
                                onColumnOrderChange={hasFixedColumnOrder ? undefined : saveColumnOrder}
                                onColumnWidthsChange={saveColumnWidths}
                                onContactOpen={openContactList}
                                onContactCreate={openContactCreate}
                                canCreateContact={Boolean(contactCan.create && contactObject)}
                            />
                        </Suspense>
                        <ObjectPagination records={records} />
                    </div>
            </div>
            {mode === 'create' && can.create && (
                <Modal title={`新建${objectLabel}`} closeHref={closeHref}>
                    <form onSubmit={create}>
                        <RecordForm
                            objectKey={currentObject.key}
                            fields={orderedFields}
                            payload={createForm.data.payload}
                            setPayload={(payload) => createForm.setData('payload', payload)}
                            processing={createForm.processing || createCheckingCustomer}
                            errors={{ ...createForm.errors, customer_profile: createCustomerError }}
                            submitLabel={`新建${objectLabel}`}
                            relationOptions={relationOptions}
                            canManageCustomers={can.manage_customers}
                        />
                    </form>
                </Modal>
            )}
            {createCustomerConflicts.length > 0 && (
                <CustomerProfileConflictDialog
                    conflicts={createCustomerConflicts}
                    processing={createForm.processing}
                    onCancel={() => setCreateCustomerConflicts([])}
                    onConfirm={() => {
                        setCreateCustomerConflicts([]);
                        submitCreateProject(true, true);
                    }}
                />
            )}
            {mode === 'detail' && selectedRecord && (
                <Modal title={`${selectedRecord.code} · 详情`} closeHref={closeHref}>
                    <RecordDetail object={currentObject} record={selectedRecord} fields={orderedFields} relationOptions={relationOptions} contactCan={contactCan} onContactDetail={openContactDetail} onContactCreate={openContactCreate} can={can} />
                </Modal>
            )}
            {mode === 'convert' && can.convert && selectedRecord && !selectedRecord.payload?.converted_project_id && (
                <TenderConversionModal
                    record={selectedRecord}
                    businessUsers={businessUsers}
                    closeHref={`/objects/tender?record=${selectedRecord.id}&mode=detail`}
                />
            )}
            {mode === 'edit' && can.update && selectedRecord && selectedRecord.can_update !== false && (
                <EditRecordModal key={selectedRecord.id} object={currentObject} record={selectedRecord} fields={orderedFields} relationOptions={relationOptions} closeHref={closeHref} canManageCustomers={can.manage_customers} />
            )}
            {contactModal && (
                <CustomerContactModal
                    key={`${contactModal.mode}-${contactModal.contact?.id || contactModal.customer.id}`}
                    mode={contactModal.mode}
                    contactObjectId={contactObject?.id}
                    customer={contactModal.customer}
                    contacts={contactModal.contacts || contactModal.customer.contacts || []}
                    contact={contactModal.contact}
                    can={contactCan}
                    onSaved={contactSaved}
                    onDeleted={contactDeleted}
                    onClose={() => setContactModal(null)}
                />
            )}
        </Layout>
    );
}

function ObjectSwitcher({ objects, currentObject }) {
    const groupObjects = currentObject.group
        ? objects.filter((object) => object.group === currentObject.group)
        : objects;
    const tabObjects = groupObjects;
    const groupOptions = [...objects.reduce((groups, object) => {
        if (object.group && !groups.has(object.group)) {
            groups.set(object.group, object);
        }

        return groups;
    }, new Map()).entries()];

    return (
        <div className="object-switcher-wrap">
            <label className="object-module-mobile">
                <span>业务模块</span>
                <select
                    value={currentObject.group || ''}
                    onChange={(event) => {
                        const firstObject = groupOptions.find(([group]) => group === event.target.value)?.[1];
                        if (firstObject) router.visit(`/objects/${firstObject.key}`);
                    }}
                >
                    {groupOptions.map(([group]) => <option key={group} value={group}>{businessText(group)}</option>)}
                </select>
            </label>
            <nav className="object-switcher" aria-label="业务资料类型">
                <div className="object-switcher-tabs">
                    {tabObjects.map((object) => (
                        <Link
                            key={object.key}
                            href={`/objects/${object.key}`}
                            className={object.key === currentObject.key ? 'active' : ''}
                            aria-current={object.key === currentObject.key ? 'page' : undefined}
                        >
                            {businessText(object.label)}
                        </Link>
                    ))}
                </div>
                <span className="object-switcher-meta">
                    {businessText(currentObject.group)} · {tabObjects.length} 张表
                </span>
            </nav>
        </div>
    );
}

function ObjectListControls({ objectKey, params, records, fields = [], relationOptions = {}, actions = null }) {
    const preserved = [...params.entries()].filter(([key]) => !['q', 'per_page', 'page', 'sort', 'direction', 'filter_logic'].includes(key) && !key.startsWith('filters['));
    const sortable = fields.filter((field) => field.scope !== 'item'
        && !['relation', 'multirelation', 'multiaccount', 'creatable_relation', 'file', 'files'].includes(field.type));
    const filterable = fields.filter((field) => field.scope !== 'item' && !['file', 'files', 'multirelation', 'multiaccount'].includes(field.type));
    const initialFilters = filterRowsFromParams(params, filterable);
    const [filters, setFilters] = useState(initialFilters.length ? initialFilters : []);
    const [filterPanelOpen, setFilterPanelOpen] = useState(false);
    const filterPopoverRef = useRef(null);

    useEffect(() => {
        if (!filterPanelOpen) {
            return undefined;
        }

        function closeFilterPanel(event) {
            if (event.type === 'keydown' && event.key !== 'Escape') {
                return;
            }

            if (event.type === 'mousedown' && filterPopoverRef.current?.contains(event.target)) {
                return;
            }

            setFilterPanelOpen(false);
        }

        document.addEventListener('mousedown', closeFilterPanel);
        document.addEventListener('keydown', closeFilterPanel);

        return () => {
            document.removeEventListener('mousedown', closeFilterPanel);
            document.removeEventListener('keydown', closeFilterPanel);
        };
    }, [filterPanelOpen]);

    function updateFilter(index, key, value) {
        setFilters((current) => current.map((filter, position) => position === index ? { ...filter, [key]: value } : filter));
    }

    return (
        <form className="object-list-controls advanced-filter-form" method="get" action={`/objects/${objectKey}`} role="search" aria-label="业务数据筛选">
            {preserved.map(([key, value], index) => (
                <input key={`${key}-${value}-${index}`} type="hidden" name={key} value={value} />
            ))}
            <div className="object-list-primary">
                <label className="object-search">
                    <Search size={16} />
                    <span className="sr-only">搜索</span>
                    <input type="search" name="q" aria-label="搜索记录" defaultValue={params.get('q') || ''} placeholder="搜索编号、标题或字段内容" />
                </label>
                <label>
                    <span className="sr-only">每页</span>
                    <select name="per_page" aria-label="每页条数" defaultValue={String(records.per_page || 50)}>
                        {PAGE_SIZE_OPTIONS.map((pageSize) => (
                            <option value={pageSize} key={pageSize}>{pageSize} 条</option>
                        ))}
                    </select>
                </label>
                <label>
                    <span className="sr-only">排序</span>
                    <select name="sort" aria-label="排序字段" defaultValue={params.get('sort') || ''}>
                        <option value="">{objectKey === 'project' ? '默认（项目名称）' : '默认（最近更新）'}</option>
                        {sortable.map((field) => <option value={field.key} key={field.key}>{field.label}</option>)}
                    </select>
                </label>
                <label>
                    <span className="sr-only">方向</span>
                    <select name="direction" aria-label="排序方向" defaultValue={params.get('direction') === 'desc' ? 'desc' : 'asc'}>
                        <option value="asc">升序</option>
                        <option value="desc">降序</option>
                    </select>
                </label>
                <button type="submit" className="filter-button"><SlidersHorizontal size={15} /> 应用</button>
                <div className="advanced-filter-popover-shell" ref={filterPopoverRef}>
                    <button
                        type="button"
                        className={`advanced-filter-trigger${initialFilters.length ? ' active' : ''}`}
                        aria-expanded={filterPanelOpen}
                        aria-controls="advanced-filter-panel"
                        onClick={() => setFilterPanelOpen((open) => !open)}
                    >
                        <SlidersHorizontal size={15} /> {initialFilters.length ? `${initialFilters.length} 筛选` : '筛选'}
                    </button>
                    <div
                        className="advanced-filter-panel"
                        id="advanced-filter-panel"
                        role="dialog"
                        aria-label="设置筛选条件"
                        hidden={!filterPanelOpen}
                    >
                        <div className="advanced-filter-title">
                            <strong>设置筛选条件</strong>
                            <button type="button" className="icon-link" aria-label="关闭筛选条件" onClick={() => setFilterPanelOpen(false)}><X size={17} /></button>
                        </div>
                        <div className="advanced-filter-logic">
                            <span>条件关系</span>
                            <select name="filter_logic" aria-label="条件关系" defaultValue={params.get('filter_logic') === 'or' ? 'or' : 'and'}>
                                <option value="and">全部满足（AND）</option>
                                <option value="or">任一满足（OR）</option>
                            </select>
                        </div>
                        <div className="advanced-filter-rows">
                            {filters.map((filter, index) => {
                                const field = filterable.find((candidate) => candidate.key === filter.field) || filterable[0];
                                const operators = filterOperators(field);
                                return (
                                    <div className="advanced-filter-row" key={`${index}-${filter.field}`}>
                                        <select name={`filters[${index}][field]`} value={field?.key || ''} onChange={(event) => updateFilter(index, 'field', event.target.value)} aria-label={`筛选字段 ${index + 1}`}>
                                            {filterable.map((candidate) => <option key={candidate.key} value={candidate.key}>{candidate.label}</option>)}
                                        </select>
                                        <select name={`filters[${index}][operator]`} value={operators.some((option) => option.value === filter.operator) ? filter.operator : operators[0]?.value} onChange={(event) => updateFilter(index, 'operator', event.target.value)} aria-label={`筛选条件 ${index + 1}`}>
                                            {operators.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                                        </select>
                                        <FilterValueInput field={field} operator={filter.operator} name={`filters[${index}][value]`} value={filter.value} onChange={(value) => updateFilter(index, 'value', value)} relationOptions={relationOptions} />
                                        <button type="button" className="icon-link" aria-label={`删除筛选条件 ${index + 1}`} onClick={() => setFilters((current) => current.filter((_, position) => position !== index))}><Trash2 size={15} /></button>
                                    </div>
                                );
                            })}
                        </div>
                        <div className="advanced-filter-actions">
                            <button type="button" className="advanced-filter-add" onClick={() => setFilters((current) => [...current, emptyFilter(filterable)])}>
                                <Plus size={15} /> 添加条件
                            </button>
                            <div>
                                {initialFilters.length > 0 && <a className="small-action secondary-button" href={clearFilterUrl(objectKey, params)}>清空筛选</a>}
                                <button type="submit" className="small-action action-button" onClick={() => setFilterPanelOpen(false)}>应用筛选</button>
                            </div>
                        </div>
                    </div>
                </div>
                {actions}
            </div>
        </form>
    );
}

function filterRowsFromParams(params, fields) {
    const rows = new Map();
    for (const [key, value] of params.entries()) {
        const match = key.match(/^filters\[(\d+)]\[(field|operator|value)]$/);
        if (!match) continue;
        const index = Number(match[1]);
        rows.set(index, { ...(rows.get(index) || {}), [match[2]]: value });
    }

    return [...rows.values()].filter((row) => fields.some((field) => field.key === row.field));
}

function emptyFilter(fields) {
    const field = fields[0];
    return { field: field?.key || '', operator: filterOperators(field)[0]?.value || 'contains', value: '' };
}

function filterOperators(field) {
    if (['number', 'range'].includes(field?.type)) return [
        ['equals', '等于'], ['not_equals', '不等于'], ['greater_than', '大于'], ['greater_or_equal', '大于等于'], ['less_than', '小于'], ['less_or_equal', '小于等于'], ['between', '介于'], ['is_empty', '为空'], ['is_not_empty', '不为空'],
    ].map(([value, label]) => ({ value, label }));
    if (field?.type === 'date') return [
        ['equals', '等于'], ['before', '早于'], ['on_or_before', '早于或等于'], ['after', '晚于'], ['on_or_after', '晚于或等于'], ['between', '日期区间'], ['is_empty', '为空'], ['is_not_empty', '不为空'],
    ].map(([value, label]) => ({ value, label }));
    if (['select', 'relation', 'account'].includes(field?.type)) return [
        { value: 'equals', label: '等于' }, { value: 'not_equals', label: '不等于' }, { value: 'is_empty', label: '为空' }, { value: 'is_not_empty', label: '不为空' },
    ];
    return [
        { value: 'contains', label: '包含' }, { value: 'not_contains', label: '不包含' }, { value: 'equals', label: '等于' }, { value: 'not_equals', label: '不等于' }, { value: 'is_empty', label: '为空' }, { value: 'is_not_empty', label: '不为空' },
    ];
}

function FilterValueInput({ field, operator, name, value, onChange, relationOptions }) {
    if (['is_empty', 'is_not_empty'].includes(operator)) return <input type="hidden" name={name} value="1" />;
    if (operator === 'between') {
        const [start = '', end = ''] = String(value || '').split('..', 2);
        const type = field?.type === 'date' ? 'date' : 'number';
        return <div className="filter-range"><input type={type} value={start} onChange={(event) => onChange(`${event.target.value}..${end}`)} aria-label={`${field?.label}起始值`} /><span>至</span><input type={type} value={end} onChange={(event) => onChange(`${start}..${event.target.value}`)} aria-label={`${field?.label}结束值`} /><input type="hidden" name={name} value={value || ''} /></div>;
    }
    if (field?.type === 'select') {
        return <select name={name} value={value || ''} onChange={(event) => onChange(event.target.value)}><option value="">请选择</option>{(field.options || []).map((option) => <option key={option} value={option}>{option}</option>)}</select>;
    }
    if (['relation', 'account'].includes(field?.type)) {
        const options = relationOptions[field.key]?.items || [];
        return <select name={name} value={value || ''} onChange={(event) => onChange(event.target.value)}><option value="">请选择</option>{options.map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}</select>;
    }
    return <input name={name} type={field?.type === 'date' ? 'date' : field?.type === 'number' || field?.type === 'range' ? 'number' : 'text'} value={value || ''} onChange={(event) => onChange(event.target.value)} placeholder="筛选值" />;
}

function clearFilterUrl(objectKey, params) {
    const next = new URLSearchParams();
    for (const [key, value] of params.entries()) {
        if (key !== 'filter_logic' && !key.startsWith('filters[') && key !== 'page') next.append(key, value);
    }
    return `/objects/${objectKey}${next.toString() ? `?${next}` : ''}`;
}

function ObjectPagination({ records }) {
    if (!records.last_page || records.last_page <= 1) return null;

    return (
        <nav className="object-pagination" aria-label="业务数据分页">
            {records.prev_page_url ? (
                <Link className="small-action" href={records.prev_page_url} preserveScroll>上一页</Link>
            ) : <span className="disabled">上一页</span>}
            <span>第 {records.current_page} / {records.last_page} 页，共 {records.total} 条</span>
            {records.next_page_url ? (
                <Link className="small-action" href={records.next_page_url} preserveScroll>下一页</Link>
            ) : <span className="disabled">下一页</span>}
        </nav>
    );
}

function exportUrlFor(objectKey, params) {
    const exportParams = new URLSearchParams();
    for (const [key, value] of params.entries()) {
        if (['q', 'sort', 'direction', 'filter_logic'].includes(key) || key.startsWith('filters[')) {
            exportParams.append(key, value);
        }
    }
    const query = exportParams.toString();

    return `/objects/${objectKey}/export.csv${query ? `?${query}` : ''}`;
}

function EditRecordModal({ object, record, fields, relationOptions, closeHref, canManageCustomers = false }) {
    const updateForm = useForm({ payload: payloadForEdit(record.payload, fields) });
    const submittingRef = useRef(false);
    const checkingCustomerRef = useRef(false);
    const [checkingCustomer, setCheckingCustomer] = useState(false);
    const [customerError, setCustomerError] = useState('');
    const [customerConflicts, setCustomerConflicts] = useState([]);

    async function update(event) {
        event.preventDefault();
        await submitUpdate(false, false);
    }

    async function submitUpdate(overwriteConfirmed, skipPreview) {
        if (submittingRef.current || checkingCustomerRef.current) return;

        const profile = updateForm.data.payload.customer_profile;
        if (object.key === 'project' && canManageCustomers && profile && !skipPreview) {
            checkingCustomerRef.current = true;
            setCheckingCustomer(true);
            setCustomerError('');
            try {
                const preview = await previewProjectCustomerProfile(profile);
                if (preview.conflicts?.length) {
                    setCustomerConflicts(preview.conflicts);
                    return;
                }
            } catch (error) {
                setCustomerError(error.message || '客户资料检查失败。');
                return;
            } finally {
                checkingCustomerRef.current = false;
                setCheckingCustomer(false);
            }
        }

        submittingRef.current = true;
        updateForm.transform((data) => withCustomerProfile(data, profile, overwriteConfirmed));
        updateForm.put(`/records/${record.id}?return_to=${encodeURIComponent(closeHref)}`, {
            preserveScroll: true,
            onFinish: () => {
                submittingRef.current = false;
            },
        });
    }

    return (
        <>
            <Modal title={`${record.code} · 编辑`} closeHref={closeHref}>
                <form onSubmit={update}>
                    <RecordForm
                        objectKey={object.key}
                        record={record}
                        fields={fields}
                        payload={updateForm.data.payload}
                        setPayload={(payload) => updateForm.setData('payload', payload)}
                        processing={updateForm.processing || checkingCustomer}
                        errors={{ ...updateForm.errors, customer_profile: customerError }}
                        submitLabel="保存"
                        relationOptions={relationOptions}
                        recordDisplay={record.display}
                        canManageCustomers={canManageCustomers}
                    />
                </form>
            </Modal>
            {customerConflicts.length > 0 && (
                <CustomerProfileConflictDialog
                    conflicts={customerConflicts}
                    processing={updateForm.processing}
                    onCancel={() => setCustomerConflicts([])}
                    onConfirm={() => {
                        setCustomerConflicts([]);
                        submitUpdate(true, true);
                    }}
                />
            )}
        </>
    );
}

function withCustomerProfile(data, profile, overwriteConfirmed) {
    if (!profile) return data;

    return {
        ...data,
        payload: {
            ...data.payload,
            customer_profile: normalizedProjectCustomerProfile(profile, overwriteConfirmed),
        },
    };
}

function payloadForEdit(payload, fields) {
    const next = { ...payload };
    const itemFields = fields.filter((field) => field.scope === 'item');
    if (itemFields.length && (!Array.isArray(next.items) || next.items.length === 0)) {
        next.items = [emptyItem(itemFields)];
    }

    return next;
}

function Modal({ title, closeHref, children }) {
    return (
        <div className="modal-backdrop" role="dialog" aria-modal="true" aria-label={title}>
            <section className="modal-panel">
                <div className="modal-head">
                    <div>
                        <h2>{title}</h2>
                        <p>请按业务实际情况填写，保存前可再次检查</p>
                    </div>
                    <Link href={closeHref} preserveScroll className="icon-link" title="关闭" aria-label={`关闭${title}`}>
                        <X size={16} />
                    </Link>
                </div>
                <div className="modal-body">{children}</div>
            </section>
        </div>
    );
}

function RecordForm({ objectKey, record = null, fields, payload, setPayload, processing, errors, submitLabel, relationOptions, recordDisplay = {}, canManageCustomers = false }) {
    const itemFields = fields.filter((field) => field.scope === 'item');
    const customerProfileFieldKeys = new Set(['customer_id', 'customer_contact_ids', 'customer_address', 'customer_level', 'customer_nature']);
    const formFields = objectKey === 'customer'
        ? fields.filter((field) => field.key !== 'cooperation_history')
        : fields.filter((field) => !(objectKey === 'project' && canManageCustomers && customerProfileFieldKeys.has(field.key)));
    const [contactClearNotice, setContactClearNotice] = useState('');
    const scopedOptions = scopedRelationOptions(relationOptions, payload, recordDisplay);
    const selectedTeam = scopedOptions.team_id?.items?.find((item) => item.id === payload.team_id);
    const shownLeader = Object.prototype.hasOwnProperty.call(payload, 'team_leader_name')
        ? payload.team_leader_name
        : selectedTeam?.meta?.leader_name;
    const customerProfile = payload.customer_profile || projectCustomerProfile(payload, record, relationOptions);

    function setField(key, value) {
        const next = { ...payload, [key]: value };
        if (key === 'team_id' && value !== payload.team_id) {
            delete next.team_leader_name;
        }
        if (key === 'customer_id' && value !== payload.customer_id && Array.isArray(next.customer_contact_ids)) {
            const previousCount = next.customer_contact_ids.length;
            const allowed = new Set((relationOptions.customer_contact_ids?.items || [])
                .filter((item) => item.meta?.customer_id === value)
                .map((item) => item.id));
            next.customer_contact_ids = next.customer_contact_ids.filter((id) => allowed.has(id));
            const clearedCount = previousCount - next.customer_contact_ids.length;
            setContactClearNotice(clearedCount > 0
                ? `客户已变更，已清除 ${clearedCount} 位不属于新客户的联系人。`
                : '');
        }
        setPayload(next);
    }

    function setCustomerProfile(profile) {
        setPayload({
            ...payload,
            customer_id: profile.customer_id || '',
            customer_contact_ids: profile.contacts.filter((contact) => contact.id).map((contact) => contact.id),
            customer_profile: profile,
        });
    }

    return (
        <SchemaForm
            fields={formFields}
            data={payload}
            setData={setField}
            processing={processing}
            submitLabel={submitLabel}
            relationOptions={scopedOptions}
        >
            {objectKey === 'customer' && (
                <CustomerProjectHistory projects={record?.cooperation_projects || []} />
            )}
            {objectKey === 'project' && canManageCustomers && (
                <ProjectCustomerInlineFields
                    profile={customerProfile}
                    onChange={setCustomerProfile}
                    customerOptions={relationOptions.customer_id || {}}
                    contactOptions={scopedOptions.customer_contact_ids || {}}
                />
            )}
            {itemFields.length > 0 && (
                <LineItemsEditor
                    fields={itemFields}
                    items={payload.items}
                    onChange={(items) => setPayload({ ...payload, items })}
                    relationOptions={scopedOptions}
                />
            )}
            {fields.some((field) => field.key === 'team_leader_name') && payload.team_id && (
                <div className="readonly-preview">
                    <span>班组负责人</span>
                    <strong>{shownLeader || '暂未配置'}</strong>
                </div>
            )}
            {contactClearNotice && <p className="notice form-notice" role="status">{contactClearNotice}</p>}
            {Object.values(errors || {}).filter(Boolean).map((error, index) => (
                <p className="form-error" key={`${error}-${index}`}>{error}</p>
            ))}
        </SchemaForm>
    );
}

function RecordDetail({ object, record, fields, relationOptions, contactCan, onContactDetail, onContactCreate, can = {} }) {
    const commonFields = fields.filter((field) => field.scope !== 'item'
        && !(object.key === 'project' && field.key === 'customer_contact_ids')
        && !(object.key === 'customer' && field.key === 'cooperation_history'));
    const itemFields = fields.filter((field) => field.scope === 'item');

    return (
        <>
            <div className="detail-grid">
                <div>
                    <span>编号</span>
                    <strong className="mono">{record.code}</strong>
                </div>
                <div>
                    <span>标题</span>
                    <strong>{record.title}</strong>
                </div>
                {commonFields.map((field) => (
                    <div key={field.key}>
                        <span>{field.label}</span>
                        <strong>{cellValue(object.key, field, record, relationOptions) || '未填写'}</strong>
                    </div>
                ))}
            </div>
            {object.key === 'customer' && <CustomerProjectHistory projects={record.cooperation_projects || []} />}
            {itemFields.length > 0 && (
                <LineItemsDetail fields={itemFields} items={record.payload?.items || []} relationOptions={relationOptions} />
            )}
            {object.key === 'project' && <ProjectContacts contacts={record.contacts || []} />}
            {object.key === 'project' && can.sync_contract_amount && <ContractAmountSync project={record} />}
            {object.key === 'customer' && <CustomerContacts customer={record} contacts={record.contacts || []} can={contactCan} onDetail={onContactDetail} onCreate={onContactCreate} />}
            {object.key === 'tender' && can.convert && !record.payload?.converted_project_id && (
                <div className="form-actions">
                    <span>确认中标后将创建项目主档并指派接手业务员。</span>
                    <Link className="action-button" href={`/objects/tender?record=${record.id}&mode=convert`} preserveScroll>
                        确认中标并流转
                    </Link>
                </div>
            )}
        </>
    );
}

function ContractAmountSync({ project }) {
    const [syncing, setSyncing] = useState(false);
    const source = project.payload?.contract_amount_source === 'contract_sync' ? '合同表汇总' : '财务手工维护';
    const syncedAt = project.payload?.contract_amount_synced_at;

    async function sync() {
        if (!window.confirm('确定以合同表最新金额汇总覆盖项目合同金额吗？')) return;
        setSyncing(true);
        try {
            const response = await fetch(`/projects/${project.id}/contract-amount/sync`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            });
            if (!response.ok) throw new Error('同步失败');
            router.reload({ only: ['records', 'selectedRecord'], preserveScroll: true });
        } finally {
            setSyncing(false);
        }
    }

    return (
        <section className="contract-sync-card">
            <div><strong>合同金额维护来源：{source}</strong><span>最后同步：{syncedAt ? new Date(syncedAt).toLocaleString('zh-CN') : '尚未主动同步'}</span></div>
            <button type="button" className="secondary-button small-action" onClick={sync} disabled={syncing}><RefreshCw size={14} /> {syncing ? '同步中…' : '从合同表重新同步'}</button>
        </section>
    );
}

function TenderConversionModal({ record, businessUsers, closeHref }) {
    const form = useForm({ assignee_user_id: '' });

    function submit(event) {
        event.preventDefault();
        form.post(`/records/${record.id}/convert-to-project`, { preserveScroll: true });
    }

    return (
        <Modal title="确认中标并流转" closeHref={closeHref}>
            <form onSubmit={submit}>
                <div className="form-grid">
                    <div className="notice form-notice wide">
                        招投标「{record.title}」将标记为已中标，并创建对应项目主档。此动作不会自动覆盖已有项目。
                    </div>
                    <label className="wide">
                        <span>接手业务员<b>*</b></span>
                        <select
                            aria-label="接手业务员"
                            value={form.data.assignee_user_id}
                            required
                            onChange={(event) => form.setData('assignee_user_id', event.target.value)}
                        >
                            <option value="">请选择业务员</option>
                            {businessUsers.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                        </select>
                    </label>
                    {form.errors.assignee_user_id && <p className="form-error wide">{form.errors.assignee_user_id}</p>}
                    <div className="form-actions wide">
                        <span>系统会通知接手业务员与全部管理员。</span>
                        <button type="submit" disabled={form.processing || !form.data.assignee_user_id}>
                            {form.processing ? '正在流转...' : '确认中标并流转'}
                        </button>
                    </div>
                </div>
            </form>
        </Modal>
    );
}

function CustomerProjectHistory({ projects }) {
    return (
        <section className="customer-project-history wide" aria-label="合作历史">
            <div className="customer-project-history-head">
                <div>
                    <span>合作历史</span>
                    <small>根据该客户关联的项目自动生成</small>
                </div>
                <b>{projects.length} 个项目</b>
            </div>
            {projects.length ? (
                <ul>
                    {projects.map((project) => (
                        <li key={project.id}>
                            <span className="mono">{project.code || '未填写编号'}</span>
                            <strong>{project.title || '未命名项目'}</strong>
                            <time>{project.date || '未填写时间'}</time>
                        </li>
                    ))}
                </ul>
            ) : <p className="muted">暂无关联项目；项目关联此客户后会自动显示在这里。</p>}
        </section>
    );
}

function LineItemsDetail({ fields, items, relationOptions }) {
    return (
        <section className="line-items-detail">
            <div className="section-head">
                <div><p>明细列表</p><h2>规格与数量</h2></div>
                <span>共 {items.length} 条</span>
            </div>
            <div className="table-scroll">
                <table className="data-table" aria-label="单据明细">
                    <thead><tr>{fields.map((field) => <th key={field.key}>{field.label}</th>)}</tr></thead>
                    <tbody>
                        {items.map((item, index) => (
                            <tr key={item.id || index}>
                                {fields.map((field) => <td key={field.key}>{itemCellValue(field, item, relationOptions) || '未填写'}</td>)}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function ProjectContacts({ contacts }) {
    return (
        <section>
            <div className="section-head">
                <div>
                    <p>客户档案</p>
                    <h2>项目联系人</h2>
                </div>
                <span>共 {contacts.length} 位联系人</span>
            </div>
            {contacts.length ? (
                <div className="table-scroll">
                    <table className="data-table" aria-label="项目联系人">
                        <thead><tr><th>联系人姓名</th><th>联系电话</th></tr></thead>
                        <tbody>
                            {contacts.map((contact) => (
                                <tr key={contact.id}>
                                    <td><Link href={`/objects/customer?record=${contact.customer_id}&mode=detail`}>{contact.name || '未命名联系人'}</Link></td>
                                    <td>{contact.phone || '未填写'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : <p className="muted">暂无联系人。</p>}
        </section>
    );
}

function CustomerContacts({ customer, contacts, can = {}, onDetail, onCreate }) {
    return (
        <section>
            <div className="section-head">
                <div>
                    <p>客户档案</p>
                    <h2>客户联系人</h2>
                </div>
                <div className="section-actions">
                    <span>共 {contacts.length} 位联系人</span>
                    {can.create && (
                        <button type="button" className="small-action action-button" onClick={() => onCreate?.(customer)}>
                            <Plus size={15} /> 新增联系人
                        </button>
                    )}
                </div>
            </div>
            {contacts.length ? (
                <ul className="contact-detail-list" aria-label="客户联系人">
                    {contacts.map((contact) => (
                        <li key={contact.id}>
                            <button type="button" className="contact-detail-primary" onClick={() => onDetail?.(contact, customer)}>
                                <strong>{contact.name || '未命名联系人'}</strong>
                                <span>{contact.phone || '未填写电话'}</span>
                            </button>
                            <div className="contact-detail-projects">
                                {(contact.projects || []).length
                                    ? contact.projects.map((project) => <span className="relation-chip" key={project.id}>{project.title}</span>)
                                    : <span className="muted">未关联项目</span>}
                            </div>
                        </li>
                    ))}
                </ul>
            ) : <p className="muted">暂无联系人。</p>}
        </section>
    );
}

function defaults(fields, params = null) {
    const itemFields = fields.filter((field) => field.scope === 'item');
    const payload = fields.reduce((data, field) => {
        if (field.scope !== 'item' && !field.readonly && !['readonly', 'lookup', 'derived'].includes(field.type)) {
            data[field.key] = field.default ?? (['multirelation', 'multiaccount'].includes(field.type) ? [] : '');
        }
        return data;
    }, {});

    if (itemFields.length) payload.items = [emptyItem(itemFields)];
    const customerId = params?.get('customer_id');
    if (customerId && fields.some((field) => field.key === 'customer_id')) {
        payload.customer_id = customerId;
    }

    return payload;
}

function cellValue(objectKey, field, record, relationOptions) {
    const value = record.display?.[field.key] ?? record.payload?.[field.key];
    if (value === null || value === undefined || value === '') return '';
    if (field.type === 'relation') return <span className="relation-chip">{value}</span>;
    if (['multirelation', 'multiaccount'].includes(field.type)) return (Array.isArray(value) ? value : []).map((label, index) => <span className="relation-chip" key={`${label}-${index}`}>{label}</span>);
    if (field.type === 'file') return <a className="relation-chip" href={value} target="_blank" rel="noreferrer">查看附件</a>;
    if (field.type === 'files') return (Array.isArray(value) ? value : []).map((url, index) => <a className="relation-chip" href={url} target="_blank" rel="noreferrer" key={`${url}-${index}`}>附件 {index + 1}</a>);
    return String(formatObjectNumber(objectKey, field, value));
}

export function objectListHref(objectKey, params) {
    const listParams = new URLSearchParams(params);
    listParams.delete('mode');
    listParams.delete('record');
    const query = listParams.toString();

    return `/objects/${objectKey}${query ? `?${query}` : ''}`;
}

export function recordListHrefForObject(objectKey, params) {
    return objectKey === 'project' ? objectListHref(objectKey, params) : null;
}

function itemCellValue(field, item, relationOptions) {
    const value = item[field.key];
    if (value === null || value === undefined || value === '') return '';
    if (['relation', 'creatable_relation'].includes(field.type)) {
        const snapshot = item._snapshots?.[field.key];
        if (snapshot?.id === value && snapshot.label) return snapshot.label;
        const option = relationOptions[field.key]?.items?.find((candidate) => candidate.id === value);
        return option?.label || value;
    }

    return String(value);
}
