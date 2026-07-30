import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Download, Plus, Search, SlidersHorizontal, X } from 'lucide-react';
import { lazy, Suspense, useEffect, useMemo, useState } from 'react';
import Layout from '../../Components/Layout';
import CustomerContactModal from '../../Components/CustomerContactModal';
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

const ObjectGrid = lazy(() => import('../../Components/ObjectGrid'));

export default function Index({ objects = [], currentObject, records, can, relationOptions, selectedRecordId, selectedRecord: selectedRecordProp = null }) {
    const { auth } = usePage().props;
    const params = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search);
    const mode = params.get('mode');
    const [tableRecords, setTableRecords] = useState(records.data);
    const [contactModal, setContactModal] = useState(null);
    const selectedRecord = tableRecords.find((record) => record.id === selectedRecordId) || selectedRecordProp;
    const closeHref = `/objects/${currentObject.key}`;
    const storageKey = columnOrderStorageKey(auth.user?.id ?? 'anonymous', currentObject.key);
    const widthStorageKey = columnWidthStorageKey(auth.user?.id ?? 'anonymous', currentObject.key);
    const [columnOrder, setColumnOrder] = useState(() => readColumnOrder(storageKey));
    const [columnWidths, setColumnWidths] = useState(() => readColumnWidths(widthStorageKey));
    const orderedFields = useMemo(
        () => fieldsInColumnOrder(currentObject.fields, columnOrder),
        [columnOrder, currentObject.fields],
    );
    const createForm = useForm({ payload: defaults(currentObject.fields, params) });
    const contactCan = {
        create: (auth.permissions || []).includes('object.customer_contact.create'),
        update: (auth.permissions || []).includes('object.customer_contact.update'),
    };
    const contactObject = objects.find((object) => object.key === 'customer_contact');
    const exportUrl = exportUrlFor(currentObject.key, params);
    const objectLabel = businessText(currentObject.label);

    useEffect(() => {
        setTableRecords(records.data);
    }, [records.data]);

    useEffect(() => {
        setColumnOrder(readColumnOrder(storageKey));
    }, [storageKey]);

    useEffect(() => {
        setColumnWidths(readColumnWidths(widthStorageKey));
    }, [widthStorageKey]);

    function updateTableRecord(nextRecord) {
        setTableRecords((current) => current.map((record) => record.id === nextRecord.id ? nextRecord : record));
    }

    function create(event) {
        event.preventDefault();
        createForm.post(`/objects/${currentObject.id}`, { preserveScroll: true });
    }

    function saveColumnOrder(order) {
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
                            <ObjectListControls objectKey={currentObject.key} params={params} records={records} fields={currentObject.fields} />
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
                        </div>
                        <Suspense fallback={<div className="table-loading">列表加载中...</div>}>
                            <ObjectGrid
                                key={storageKey}
                                object={currentObject}
                                records={tableRecords}
                                fields={orderedFields}
                                can={can}
                                selectedRecordId={selectedRecordId}
                                relationOptions={relationOptions}
                                savedColumnWidths={columnWidths}
                                onRecordChange={updateTableRecord}
                                onColumnOrderChange={saveColumnOrder}
                                onColumnWidthsChange={saveColumnWidths}
                                onContactOpen={openContactList}
                            />
                        </Suspense>
                        <ObjectPagination records={records} />
                    </div>
            </div>
            {mode === 'create' && can.create && (
                <Modal title={`新建${objectLabel}`} closeHref={closeHref}>
                    <form onSubmit={create}>
                        <RecordForm
                            fields={orderedFields}
                            payload={createForm.data.payload}
                            setPayload={(payload) => createForm.setData('payload', payload)}
                            processing={createForm.processing}
                            errors={createForm.errors}
                            submitLabel={`新建${objectLabel}`}
                            relationOptions={relationOptions}
                        />
                    </form>
                </Modal>
            )}
            {mode === 'detail' && selectedRecord && (
                <Modal title={`${selectedRecord.code} · 详情`} closeHref={closeHref}>
                    <RecordDetail object={currentObject} record={selectedRecord} fields={orderedFields} relationOptions={relationOptions} contactCan={contactCan} onContactDetail={openContactDetail} onContactCreate={openContactCreate} />
                </Modal>
            )}
            {mode === 'edit' && can.update && selectedRecord && (
                <EditRecordModal key={selectedRecord.id} record={selectedRecord} fields={orderedFields} relationOptions={relationOptions} closeHref={closeHref} />
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
    const tabObjects = groupObjects.filter((object) => object.key !== 'customer_contact');
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

function ObjectListControls({ objectKey, params, records, fields = [] }) {
    const preserved = [...params.entries()].filter(([key]) => !['q', 'per_page', 'page', 'sort', 'direction'].includes(key));
    const sortable = fields.filter((field) => field.scope !== 'item'
        && !['relation', 'multirelation', 'creatable_relation', 'file'].includes(field.type));

    return (
        <form className="object-list-controls" method="get" action={`/objects/${objectKey}`} role="search" aria-label="业务数据筛选">
            {preserved.map(([key, value], index) => (
                <input key={`${key}-${value}-${index}`} type="hidden" name={key} value={value} />
            ))}
            <label className="object-search">
                <Search size={16} />
                <span className="sr-only">搜索</span>
                <input type="search" name="q" aria-label="搜索记录" defaultValue={params.get('q') || ''} placeholder="搜索编号、标题或字段内容" />
            </label>
            <label>
                <span className="sr-only">每页</span>
                <select name="per_page" aria-label="每页条数" defaultValue={String(records.per_page || 50)}>
                    <option value="25">25 条</option>
                    <option value="50">50 条</option>
                    <option value="100">100 条</option>
                </select>
            </label>
            <label>
                <span className="sr-only">排序</span>
                <select name="sort" aria-label="排序字段" defaultValue={params.get('sort') || ''}>
                    <option value="">默认（最近更新）</option>
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
        </form>
    );
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
    for (const key of ['q', 'sort', 'direction']) {
        const value = params.get(key);
        if (value) exportParams.set(key, value);
    }
    const query = exportParams.toString();

    return `/objects/${objectKey}/export.csv${query ? `?${query}` : ''}`;
}

function EditRecordModal({ record, fields, relationOptions, closeHref }) {
    const updateForm = useForm({ payload: payloadForEdit(record.payload, fields) });

    function update(event) {
        event.preventDefault();
        updateForm.put(`/records/${record.id}`, { preserveScroll: true });
    }

    return (
        <Modal title={`${record.code} · 编辑`} closeHref={closeHref}>
            <form onSubmit={update}>
                <RecordForm
                    fields={fields}
                    payload={updateForm.data.payload}
                    setPayload={(payload) => updateForm.setData('payload', payload)}
                    processing={updateForm.processing}
                    errors={updateForm.errors}
                    submitLabel="保存"
                    relationOptions={relationOptions}
                    recordDisplay={record.display}
                />
            </form>
        </Modal>
    );
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

function RecordForm({ fields, payload, setPayload, processing, errors, submitLabel, relationOptions, recordDisplay = {} }) {
    const itemFields = fields.filter((field) => field.scope === 'item');
    const [contactClearNotice, setContactClearNotice] = useState('');
    const scopedOptions = scopedRelationOptions(relationOptions, payload, recordDisplay);
    const selectedTeam = scopedOptions.team_id?.items?.find((item) => item.id === payload.team_id);
    const shownLeader = Object.prototype.hasOwnProperty.call(payload, 'team_leader_name')
        ? payload.team_leader_name
        : selectedTeam?.meta?.leader_name;

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

    return (
        <SchemaForm
            fields={fields}
            data={payload}
            setData={setField}
            processing={processing}
            submitLabel={submitLabel}
            relationOptions={scopedOptions}
        >
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
            {Object.values(errors || {}).map((error, index) => (
                <p className="form-error" key={`${error}-${index}`}>{error}</p>
            ))}
        </SchemaForm>
    );
}

function RecordDetail({ object, record, fields, relationOptions, contactCan, onContactDetail, onContactCreate }) {
    const commonFields = fields.filter((field) => field.scope !== 'item'
        && !(object.key === 'project' && field.key === 'customer_contact_ids'));
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
                        <strong>{cellValue(field, record, relationOptions) || '未填写'}</strong>
                    </div>
                ))}
            </div>
            {itemFields.length > 0 && (
                <LineItemsDetail fields={itemFields} items={record.payload?.items || []} relationOptions={relationOptions} />
            )}
            {object.key === 'project' && <ProjectContacts contacts={record.contacts || []} />}
            {object.key === 'customer' && <CustomerContacts customer={record} contacts={record.contacts || []} can={contactCan} onDetail={onContactDetail} onCreate={onContactCreate} />}
        </>
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
            data[field.key] = field.default ?? (field.type === 'multirelation' ? [] : '');
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

function cellValue(field, record, relationOptions) {
    const value = record.display?.[field.key] ?? record.payload?.[field.key];
    if (!value) return '';
    if (field.type === 'relation') return <span className="relation-chip">{value}</span>;
    if (field.type === 'multirelation') return (Array.isArray(value) ? value : []).map((label, index) => <span className="relation-chip" key={`${label}-${index}`}>{label}</span>);
    if (field.type === 'file') return <a className="relation-chip" href={value} target="_blank" rel="noreferrer">查看附件</a>;
    return String(value);
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
