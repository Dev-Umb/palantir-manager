import { Pencil, Plus, Users, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export default function ProjectCustomerManager({ customerId, onCustomerSelected, onContactSelected, onSavingChange }) {
    const [open, setOpen] = useState(false);
    const [customer, setCustomer] = useState(null);
    const [customerDraft, setCustomerDraft] = useState(emptyCustomer());
    const [contactDraft, setContactDraft] = useState({ id: '', name: '', phone: '' });
    const [errors, setErrors] = useState([]);
    const [notice, setNotice] = useState('');
    const [saving, setSaving] = useState(false);
    const savingRef = useRef(false);

    useEffect(() => {
        if (!open || !customerId) return;
        loadCustomer(customerId);
    }, [customerId, open]);

    async function loadCustomer(id) {
        setErrors([]);
        const response = await fetch(`/project-customers/${id}`, { headers: { Accept: 'application/json' } });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            setErrors(['客户资料读取失败。']);
            return;
        }
        setCustomer(data.customer);
        setCustomerDraft({
            name: data.customer.payload?.name || data.customer.title || '',
            address: data.customer.payload?.address || '',
            level: data.customer.payload?.level || '',
            remark: data.customer.payload?.remark || '',
        });
    }

    function startNewCustomer() {
        setCustomer(null);
        setCustomerDraft(emptyCustomer());
        setContactDraft({ id: '', name: '', phone: '' });
        setErrors([]);
        setNotice('');
        setOpen(true);
    }

    async function saveCustomer() {
        if (!beginSaving()) return;
        setErrors([]);
        setNotice('');
        try {
            const response = await fetch(customer ? `/project-customers/${customer.id}` : '/project-customers', {
                method: customer ? 'PUT' : 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify(customerDraft),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(firstError(data));
            setCustomer(data.customer);
            onCustomerSelected?.(data.customer.id);
            setNotice('客户已保存并选中，请继续保存项目。');
        } catch (error) {
            setErrors([error.message || '客户保存失败。']);
        } finally {
            finishSaving();
        }
    }

    async function saveContact() {
        if (!customer) {
            setErrors(['请先保存客户，再新增联系人。']);
            return;
        }
        if (!beginSaving()) return;
        setErrors([]);
        setNotice('');
        try {
            const editing = Boolean(contactDraft.id);
            const response = await fetch(editing
                ? `/project-customers/${customer.id}/contacts/${contactDraft.id}`
                : `/project-customers/${customer.id}/contacts`, {
                method: editing ? 'PUT' : 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify({ name: contactDraft.name, phone: contactDraft.phone }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(firstError(data));
            setContactDraft({ id: '', name: '', phone: '' });
            onContactSelected?.(data.contact.id);
            await loadCustomer(customer.id);
            setNotice('联系人已保存并选中，请继续保存项目。');
        } catch (error) {
            setErrors([error.message || '联系人保存失败。']);
        } finally {
            finishSaving();
        }
    }

    function beginSaving() {
        if (savingRef.current) return false;

        savingRef.current = true;
        setSaving(true);
        onSavingChange?.(true);

        return true;
    }

    function finishSaving() {
        savingRef.current = false;
        setSaving(false);
        onSavingChange?.(false);
    }

    function preventProjectSubmit(event) {
        if (event.key === 'Enter' && event.target instanceof HTMLInputElement) {
            event.preventDefault();
            event.stopPropagation();
        }
    }

    return (
        <section className="project-customer-manager wide">
            <div>
                <strong>客户与联系人资料</strong>
                <span>客户表入口已隐藏，可在这里保留原记录并继续维护。</span>
            </div>
            <div className="section-actions">
                <button type="button" className="secondary-button small-action" onClick={startNewCustomer}><Plus size={14} /> 新增客户</button>
                {customerId && <button type="button" className="secondary-button small-action" onClick={() => setOpen(true)}><Users size={14} /> 维护当前客户</button>}
            </div>
            {open && (
                <div className="embedded-manager" role="dialog" aria-label="维护客户与联系人" onKeyDown={preventProjectSubmit}>
                    <div className="embedded-manager-head"><strong>{customer ? '编辑客户资料' : '新增客户资料'}</strong><button type="button" className="icon-link" aria-label="关闭客户维护" onClick={() => setOpen(false)}><X size={15} /></button></div>
                    <div className="embedded-manager-fields">
                        <label><span>客户名称*</span><input value={customerDraft.name} onChange={(event) => setCustomerDraft({ ...customerDraft, name: event.target.value })} /></label>
                        <label><span>客户等级</span><select value={customerDraft.level} onChange={(event) => setCustomerDraft({ ...customerDraft, level: event.target.value })}><option value="">未选择</option><option>A</option><option>B</option><option>C</option></select></label>
                        <label className="wide"><span>地址</span><input value={customerDraft.address} onChange={(event) => setCustomerDraft({ ...customerDraft, address: event.target.value })} /></label>
                        <label className="wide"><span>备注</span><input value={customerDraft.remark} onChange={(event) => setCustomerDraft({ ...customerDraft, remark: event.target.value })} /></label>
                    </div>
                    <button type="button" onClick={saveCustomer} disabled={saving}>{saving ? '保存中…' : '保存客户'}</button>
                    {customer && (
                        <div className="embedded-contacts">
                            <strong>联系人</strong>
                            {(customer.contacts || []).map((contact) => (
                                <button type="button" className="contact-maintain-row" key={contact.id} onClick={() => setContactDraft({ id: contact.id, name: contact.name || '', phone: contact.phone || '' })}>
                                    <span>{contact.name}</span><small>{contact.phone || '未填写电话'}</small><Pencil size={13} />
                                </button>
                            ))}
                            <div className="embedded-manager-fields">
                                <label><span>联系人姓名*</span><input value={contactDraft.name} onChange={(event) => setContactDraft({ ...contactDraft, name: event.target.value })} /></label>
                                <label><span>联系电话</span><input value={contactDraft.phone} onChange={(event) => setContactDraft({ ...contactDraft, phone: event.target.value })} /></label>
                            </div>
                            <button type="button" className="secondary-button" onClick={saveContact} disabled={saving}>{contactDraft.id ? '保存联系人修改' : '新增联系人'}</button>
                        </div>
                    )}
                    {notice && <p className="notice form-notice" role="status">{notice}</p>}
                    {errors.map((error) => <p className="form-error" key={error}>{error}</p>)}
                </div>
            )}
        </section>
    );
}

function emptyCustomer() {
    return { name: '', address: '', level: '', remark: '' };
}

function jsonHeaders() {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
    };
}

function firstError(data) {
    return Object.values(data.errors || {}).flat()[0] || data.message || '保存失败。';
}
