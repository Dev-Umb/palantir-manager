import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { lazy, Suspense, useEffect, useState } from 'react';
import Layout from '../../Components/Layout';
import { SchemaForm } from '../../Components/FieldControl';

const ObjectGrid = lazy(() => import('../../Components/ObjectGrid'));

export default function Index({ currentObject, records, can, relationOptions, selectedRecordId }) {
    const params = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search);
    const mode = params.get('mode');
    const [tableRecords, setTableRecords] = useState(records.data);
    const selectedRecord = tableRecords.find((record) => record.id === selectedRecordId);
    const closeHref = `/objects/${currentObject.key}`;
    const tableFields = currentObject.fields;
    const createForm = useForm({ payload: defaults(currentObject.fields) });

    useEffect(() => {
        setTableRecords(records.data);
    }, [records.data]);

    function updateTableRecord(nextRecord) {
        setTableRecords((current) => current.map((record) => record.id === nextRecord.id ? nextRecord : record));
    }

    function create(event) {
        event.preventDefault();
        createForm.post(`/objects/${currentObject.id}`, { preserveScroll: true });
    }

    return (
        <Layout
            title="本体工作台"
            eyebrow="Object / Link / Action"
        >
            <Head title="本体工作台" />
            <div className="object-main">
                    <div className="surface">
                        <div className="section-head">
                            <div>
                                <p>{currentObject.group}</p>
                                <h2>{currentObject.label}</h2>
                            </div>
                            <div className="section-actions">
                                <span className="pill">{currentObject.key}</span>
                                {can.create && (
                                    <Link className="small-action action-button" href={`/objects/${currentObject.key}?mode=create`}>
                                        <Plus size={15} /> 新建
                                    </Link>
                                )}
                            </div>
                        </div>
                        <Suspense fallback={<div className="table-loading">列表加载中...</div>}>
                            <ObjectGrid object={currentObject} records={tableRecords} fields={tableFields} can={can} selectedRecordId={selectedRecordId} relationOptions={relationOptions} onRecordChange={updateTableRecord} />
                        </Suspense>
                    </div>
            </div>
            {mode === 'create' && can.create && (
                <Modal title={`新建${currentObject.label}`} closeHref={closeHref}>
                    <form onSubmit={create}>
                        <SchemaForm fields={currentObject.fields} data={createForm.data.payload} setData={(key, value) => createForm.setData('payload', { ...createForm.data.payload, [key]: value })} processing={createForm.processing} submitLabel={`新建${currentObject.label}`} relationOptions={relationOptions} />
                    </form>
                </Modal>
            )}
            {mode === 'detail' && selectedRecord && (
                <Modal title={`${selectedRecord.code} · 详情`} closeHref={closeHref}>
                    <RecordDetail object={currentObject} record={selectedRecord} />
                </Modal>
            )}
            {mode === 'edit' && can.update && selectedRecord && (
                <EditRecordModal key={selectedRecord.id} object={currentObject} record={selectedRecord} relationOptions={relationOptions} closeHref={closeHref} />
            )}
        </Layout>
    );
}

function EditRecordModal({ object, record, relationOptions, closeHref }) {
    const updateForm = useForm({ payload: { ...record.payload } });

    function update(event) {
        event.preventDefault();
        updateForm.put(`/records/${record.id}`, { preserveScroll: true });
    }

    return (
        <Modal title={`${record.code} · 编辑`} closeHref={closeHref}>
            <form onSubmit={update}>
                <SchemaForm fields={object.fields} data={updateForm.data.payload} setData={(key, value) => updateForm.setData('payload', { ...updateForm.data.payload, [key]: value })} processing={updateForm.processing} submitLabel="保存" relationOptions={relationOptions} />
            </form>
        </Modal>
    );
}

function Modal({ title, closeHref, children }) {
    return (
        <div className="modal-backdrop" role="dialog" aria-modal="true" aria-label={title}>
            <section className="modal-panel">
                <div className="modal-head">
                    <h2>{title}</h2>
                    <Link href={closeHref} preserveScroll className="icon-link" title="关闭">
                        <X size={16} />
                    </Link>
                </div>
                {children}
            </section>
        </div>
    );
}

function RecordDetail({ object, record }) {
    return (
        <div className="detail-grid">
            <div>
                <span>编号</span>
                <strong className="mono">{record.code}</strong>
            </div>
            <div>
                <span>标题</span>
                <strong>{record.title}</strong>
            </div>
            {object.fields.map((field) => (
                <div key={field.key}>
                    <span>{field.label}</span>
                    <strong>{cellValue(field, record) || '未填写'}</strong>
                </div>
            ))}
        </div>
    );
}

function defaults(fields) {
    return fields.reduce((data, field) => {
        if (!['readonly', 'lookup', 'derived'].includes(field.type)) {
            data[field.key] = '';
        }
        return data;
    }, {});
}

function cellValue(field, record) {
    const value = record.display?.[field.key] ?? record.payload?.[field.key];
    if (!value) return '';
    if (field.type === 'relation') return <span className="relation-chip">{value}</span>;
    if (field.type === 'file') return <a className="relation-chip" href={value} target="_blank" rel="noreferrer">查看附件</a>;
    return String(value);
}
