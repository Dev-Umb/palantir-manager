import { Pencil, X } from 'lucide-react';
import { useEffect, useState } from 'react';

export default function CustomerContactModal({ mode = 'detail', contactObjectId, customer, contact = null, can = {}, onSaved, onClose }) {
    const [view, setView] = useState(mode);
    const [name, setName] = useState(contact?.name || '');
    const [phone, setPhone] = useState(contact?.phone || '');
    const [errors, setErrors] = useState([]);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        setView(mode);
        setName(contact?.name || '');
        setPhone(contact?.phone || '');
        setErrors([]);
    }, [contact, mode]);

    async function save(event) {
        event.preventDefault();
        if (saving) return;

        setSaving(true);
        setErrors([]);
        try {
            const editing = view === 'edit' && contact?.id;
            const response = await fetch(editing ? `/records/${contact.id}` : `/objects/${contactObjectId}`, {
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

    const title = view === 'detail' ? '联系人详情' : view === 'edit' ? '编辑联系人' : '新增联系人';

    return (
        <div className="modal-backdrop contact-modal-backdrop" role="dialog" aria-modal="true" aria-label={title}>
            <section className="modal-panel contact-modal-panel">
                <div className="modal-head">
                    <div>
                        <p className="contact-modal-customer">{customer?.title || '客户信息'}</p>
                        <h2>{title}</h2>
                    </div>
                    <button type="button" className="icon-link" aria-label="关闭联系人弹窗" title="关闭" onClick={onClose}>
                        <X size={16} />
                    </button>
                </div>

                {view === 'detail' ? (
                    <ContactDetail contact={contact} canUpdate={Boolean(can.update)} onEdit={() => setView('edit')} />
                ) : (
                    <form className="contact-modal-form" onSubmit={save}>
                        <label>
                            <span>联系人姓名</span>
                            <input required value={name} onChange={(event) => setName(event.target.value)} autoFocus />
                        </label>
                        <label>
                            <span>联系电话</span>
                            <input value={phone} onChange={(event) => setPhone(event.target.value)} />
                        </label>
                        {errors.map((error, index) => <p className="form-error" key={`${error}-${index}`}>{error}</p>)}
                        <div className="contact-modal-actions">
                            <button type="button" className="small-action secondary-button" onClick={onClose}>取消</button>
                            <button type="submit" disabled={saving}>{saving ? '保存中…' : '保存联系人'}</button>
                        </div>
                    </form>
                )}
            </section>
        </div>
    );
}

function ContactDetail({ contact, canUpdate, onEdit }) {
    const projects = contact?.projects || [];

    return (
        <div className="contact-modal-detail">
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
                        {projects.map((project) => <li key={project.id}>{project.title || '未命名项目'}</li>)}
                    </ul>
                ) : <p className="muted">暂无关联项目</p>}
            </section>
            {canUpdate && (
                <div className="contact-modal-actions">
                    <button type="button" className="small-action action-button" onClick={onEdit}>
                        <Pencil size={14} /> 编辑联系人
                    </button>
                </div>
            )}
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
