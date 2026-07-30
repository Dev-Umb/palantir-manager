import { ArrowLeft, BriefcaseBusiness, ChevronRight, Pencil, Plus, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { useDialogFocus } from './useDialogFocus';

export default function CustomerContactModal({ mode = 'detail', contactObjectId, customer, contacts = [], contact = null, can = {}, onSaved, onClose }) {
    const [view, setView] = useState(mode);
    const [activeContact, setActiveContact] = useState(contact);
    const [name, setName] = useState(contact?.name || '');
    const [phone, setPhone] = useState(contact?.phone || '');
    const [errors, setErrors] = useState([]);
    const [saving, setSaving] = useState(false);
    const titleId = useId();
    const panelRef = useRef(null);
    useDialogFocus(true, panelRef);

    useEffect(() => {
        setView(mode);
        setActiveContact(contact);
        setName(contact?.name || '');
        setPhone(contact?.phone || '');
        setErrors([]);
    }, [contact, mode]);

    useEffect(() => {
        function closeOnEscape(event) {
            if (event.key === 'Escape' && !saving) onClose?.();
        }

        document.addEventListener('keydown', closeOnEscape);
        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [onClose, saving]);

    async function save(event) {
        event.preventDefault();
        if (saving) return;

        setSaving(true);
        setErrors([]);
        try {
            const editing = view === 'edit' && activeContact?.id;
            const response = await fetch(editing ? `/records/${activeContact.id}` : `/objects/${contactObjectId}`, {
                method: editing ? 'PUT' : 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ payload: {
                    name: name.trim(),
                    phone: phone.trim(),
                    customer_id: customer.id,
                } }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const messages = Object.values(data.errors || {}).flat();
                throw new ContactSaveError(messages.length ? messages : [data.message || '保存失败，请重试。']);
            }

            onSaved?.(data.record);
        } catch (error) {
            setErrors(error instanceof ContactSaveError ? error.messages : ['保存失败，请重试。']);
        } finally {
            setSaving(false);
        }
    }

    function openDetail(nextContact) {
        setActiveContact(nextContact);
        setName(nextContact?.name || '');
        setPhone(nextContact?.phone || '');
        setErrors([]);
        setView('detail');
    }

    function openCreate() {
        setActiveContact(null);
        setName('');
        setPhone('');
        setErrors([]);
        setView('create');
    }

    function returnToListOrClose() {
        if (mode === 'list') {
            setView('list');
            setErrors([]);
            return;
        }

        onClose?.();
    }

    const title = view === 'list'
        ? '客户联系人'
        : view === 'detail'
            ? '联系人详情'
            : view === 'edit'
                ? '编辑联系人'
                : '新增联系人';

    return (
        <div className="modal-backdrop contact-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby={titleId}>
            <section ref={panelRef} className="modal-panel contact-modal-panel" tabIndex={-1}>
                <div className="modal-head contact-modal-head">
                    <div>
                        <h2 id={titleId}>{title}</h2>
                        <p className="contact-modal-customer">
                            <span>所属客户</span>
                            <strong>{customer?.title || '客户信息'}</strong>
                        </p>
                    </div>
                    <button type="button" className="icon-link" aria-label="关闭联系人弹窗" title="关闭" onClick={onClose}>
                        <X size={16} />
                    </button>
                </div>

                {view === 'list' && (
                    <div className="contact-modal-body">
                        <ContactList
                            contacts={contacts}
                            canCreate={Boolean(can.create && contactObjectId)}
                            onCreate={openCreate}
                            onDetail={openDetail}
                        />
                    </div>
                )}
                {view === 'detail' && (
                    <>
                        <div className="contact-modal-body">
                            <ContactDetail contact={activeContact} />
                        </div>
                        {(mode === 'list' || can.update) && (
                            <footer className="contact-modal-footer">
                                {mode === 'list' && (
                                    <button type="button" className="small-action secondary-button" onClick={() => setView('list')}>
                                        <ArrowLeft size={14} /> 返回联系人列表
                                    </button>
                                )}
                                {can.update && (
                                    <button type="button" className="small-action action-button" onClick={() => setView('edit')}>
                                        <Pencil size={14} /> 编辑联系人
                                    </button>
                                )}
                            </footer>
                        )}
                    </>
                )}
                {['create', 'edit'].includes(view) && (
                    <form className="contact-modal-form" onSubmit={save}>
                        <div className="contact-modal-body contact-modal-fields">
                            <label>
                                <span>联系人姓名</span>
                                <input required value={name} onChange={(event) => setName(event.target.value)} autoFocus />
                            </label>
                            <label>
                                <span>联系电话</span>
                                <input value={phone} onChange={(event) => setPhone(event.target.value)} />
                            </label>
                            {errors.map((error, index) => <p className="form-error" key={`${error}-${index}`}>{error}</p>)}
                        </div>
                        <footer className="contact-modal-footer">
                            <button type="button" className="small-action secondary-button" onClick={returnToListOrClose}>取消</button>
                            <button type="submit" disabled={saving}>{saving ? '保存中…' : '保存联系人'}</button>
                        </footer>
                    </form>
                )}
            </section>
        </div>
    );
}

function ContactList({ contacts, canCreate, onCreate, onDetail }) {
    return (
        <div className="contact-modal-list">
            <div className="contact-modal-list-head">
                <span>共 {contacts.length} 位联系人</span>
                {canCreate && (
                    <button type="button" className="small-action action-button" onClick={onCreate}>
                        <Plus size={14} /> 新增联系人
                    </button>
                )}
            </div>
            {contacts.length ? (
                <ul aria-label="客户联系人列表">
                    {contacts.map((item) => (
                        <li key={item.id}>
                            <button
                                type="button"
                                className="contact-modal-list-item"
                                aria-label={`查看 ${item.name || '联系人'} 详情`}
                                onClick={() => onDetail(item)}
                            >
                                <span>{item.name || '未命名联系人'}</span>
                                <span>{item.phone || '未填写电话'}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            ) : <p className="muted">暂无联系人。</p>}
        </div>
    );
}

function ContactDetail({ contact }) {
    const projects = contact?.projects || [];

    return (
        <div className="contact-modal-detail">
            <h3>基本信息</h3>
            <dl>
                <div><dt>联系人姓名</dt><dd>{contact?.name || '未命名联系人'}</dd></div>
                <div><dt>联系电话</dt><dd>{contact?.phone || '未填写'}</dd></div>
            </dl>
            <section className="contact-projects">
                <div className="contact-projects-head">
                    <h3>关联项目</h3>
                    <span>{projects.length} 个</span>
                </div>
                {projects.length ? (
                    <ul>
                        {projects.map((project) => (
                            <li key={project.id}>
                                <BriefcaseBusiness size={16} />
                                <span>{project.title || '未命名项目'}</span>
                                <ChevronRight size={16} aria-hidden="true" />
                            </li>
                        ))}
                    </ul>
                ) : <p className="muted">暂无关联项目</p>}
            </section>
        </div>
    );
}

class ContactSaveError extends Error {
    constructor(messages) {
        super(messages[0]);
        this.messages = messages;
    }
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}
