import { Pencil, Plus, Users, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export default function ProjectCustomerManager({ customerId, onCustomerSelected, onContactSelected, onSavingChange }) {
    const [open, setOpen] = useState(false);
    const [customer, setCustomer] = useState(null);
    const [customerDraft, setCustomerDraft] = useState(emptyCustomer());
    const [contactDraft, setContactDraft] = useState(emptyContact());
    const [errors, setErrors] = useState([]);
    const [notice, setNotice] = useState('');
    const [saving, setSaving] = useState(false);
    const customerRef = useRef(null);
    const customerDraftRef = useRef(emptyCustomer());
    const contactDraftRef = useRef(emptyContact());
    const requestInFlightRef = useRef(false);
    const busyRef = useRef(false);
    const queuedSaveRef = useRef(false);
    const queuedModeRef = useRef('auto');
    const inFlightSignatureRef = useRef('');

    useEffect(() => {
        if (!open || !customerId) return;
        setLatestCustomer(null);
        setLatestContactDraft(emptyContact());
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

        setLatestCustomer(data.customer);
        setLatestCustomerDraft({
            name: data.customer.payload?.name || data.customer.title || '',
            address: data.customer.payload?.address || '',
            level: data.customer.payload?.level || '',
            remark: data.customer.payload?.remark || '',
        });
    }

    function startNewCustomer() {
        setLatestCustomer(null);
        setLatestCustomerDraft(emptyCustomer());
        setLatestContactDraft(emptyContact());
        setErrors([]);
        setNotice('');
        setOpen(true);
    }

    function queueCombinedSave(mode = 'auto') {
        const currentCustomer = customerRef.current;
        const currentContact = normalizedContactDraft();
        if (mode === 'auto' && !currentCustomer) return;
        if (mode !== 'auto' && customerId && !currentCustomer) {
            setErrors(['客户资料仍在加载，请稍后再保存。']);
            return;
        }
        if (!currentContact && hasPartialContactDraft()) {
            setErrors(['联系人姓名不能为空。']);
            setNotice('');
            return;
        }

        const signature = saveSignature(currentCustomer, customerDraftRef.current, currentContact);
        if (requestInFlightRef.current && inFlightSignatureRef.current === signature) return;

        queuedSaveRef.current = true;
        if (mode !== 'auto') queuedModeRef.current = mode;
        void flushCombinedSave();
    }

    async function flushCombinedSave() {
        if (requestInFlightRef.current || !queuedSaveRef.current) return;

        const currentCustomer = customerRef.current;
        const currentContact = normalizedContactDraft();
        if (!currentContact && hasPartialContactDraft()) {
            queuedSaveRef.current = false;
            setErrors(['联系人姓名不能为空。']);
            finishBusy();
            return;
        }

        const mode = queuedModeRef.current;
        const payload = {
            ...customerDraftRef.current,
            ...(currentContact ? { contact: currentContact } : {}),
        };
        queuedSaveRef.current = false;
        queuedModeRef.current = 'auto';
        requestInFlightRef.current = true;
        inFlightSignatureRef.current = saveSignature(currentCustomer, customerDraftRef.current, currentContact);
        beginBusy();
        setErrors([]);
        setNotice('');

        try {
            const response = await fetch(currentCustomer ? `/project-customers/${currentCustomer.id}` : '/project-customers', {
                method: currentCustomer ? 'PUT' : 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify(payload),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(firstError(data));

            setLatestCustomer(data.customer);
            onCustomerSelected?.(data.customer.id);
            if (data.contact) {
                setLatestContactDraft(queuedSaveRef.current
                    ? { ...contactDraftRef.current, id: data.contact.id }
                    : {
                        id: data.contact.id,
                        name: data.contact.name || '',
                        phone: data.contact.phone || '',
                    });
                onContactSelected?.(data.contact.id);
            }
            setNotice(mode === 'auto'
                ? '客户和联系人已自动保存。'
                : data.contact
                    ? '客户和联系人已保存并选中，请继续保存项目。'
                    : '客户已保存并选中，请继续保存项目。');
        } catch (error) {
            queuedSaveRef.current = false;
            setErrors([error.message || '客户和联系人保存失败。']);
        } finally {
            requestInFlightRef.current = false;
            inFlightSignatureRef.current = '';
            if (queuedSaveRef.current) {
                void flushCombinedSave();
            } else {
                finishBusy();
            }
        }
    }

    function beginBusy() {
        if (busyRef.current) return;

        busyRef.current = true;
        setSaving(true);
        onSavingChange?.(true);
    }

    function finishBusy() {
        if (!busyRef.current) return;

        busyRef.current = false;
        setSaving(false);
        onSavingChange?.(false);
    }

    function setLatestCustomer(nextCustomer) {
        customerRef.current = nextCustomer;
        setCustomer(nextCustomer);
    }

    function setLatestCustomerDraft(nextDraft) {
        customerDraftRef.current = nextDraft;
        setCustomerDraft(nextDraft);
    }

    function setLatestContactDraft(nextDraft) {
        contactDraftRef.current = nextDraft;
        setContactDraft(nextDraft);
    }

    function normalizedContactDraft() {
        const draft = contactDraftRef.current;
        const name = draft.name.trim();
        if (!name) return null;

        return {
            ...(draft.id ? { id: draft.id } : {}),
            name,
            phone: draft.phone.trim(),
        };
    }

    function hasPartialContactDraft() {
        const draft = contactDraftRef.current;

        return Boolean(draft.id || draft.phone.trim());
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
                        <label><span>客户名称*</span><input value={customerDraft.name} onChange={(event) => setLatestCustomerDraft({ ...customerDraftRef.current, name: event.target.value })} /></label>
                        <label><span>客户等级</span><select value={customerDraft.level} onChange={(event) => setLatestCustomerDraft({ ...customerDraftRef.current, level: event.target.value })}><option value="">未选择</option><option>A</option><option>B</option><option>C</option></select></label>
                        <label className="wide"><span>地址</span><input value={customerDraft.address} onChange={(event) => setLatestCustomerDraft({ ...customerDraftRef.current, address: event.target.value })} /></label>
                        <label className="wide"><span>备注</span><input value={customerDraft.remark} onChange={(event) => setLatestCustomerDraft({ ...customerDraftRef.current, remark: event.target.value })} /></label>
                    </div>
                    <button type="button" onClick={() => queueCombinedSave('manual')} disabled={saving || Boolean(customerId && !customer)}>{saving ? '保存中…' : customerId && !customer ? '客户加载中…' : '保存客户'}</button>
                    <div className="embedded-contacts">
                        <strong>联系人</strong>
                        {(customer?.contacts || []).map((contact) => (
                            <button type="button" disabled={saving} className="contact-maintain-row" key={contact.id} onClick={() => setLatestContactDraft({ id: contact.id, name: contact.name || '', phone: contact.phone || '' })}>
                                <span>{contact.name}</span><small>{contact.phone || '未填写电话'}</small><Pencil size={13} />
                            </button>
                        ))}
                        <div className="embedded-manager-fields">
                            <label><span>联系人姓名*</span><input value={contactDraft.name} onChange={(event) => setLatestContactDraft({ ...contactDraftRef.current, name: event.target.value })} onBlur={() => queueCombinedSave('auto')} /></label>
                            <label><span>联系电话</span><input value={contactDraft.phone} onChange={(event) => setLatestContactDraft({ ...contactDraftRef.current, phone: event.target.value })} onBlur={() => queueCombinedSave('auto')} /></label>
                        </div>
                        <button type="button" className="secondary-button" onClick={() => queueCombinedSave('manual')} disabled={saving}>{contactDraft.id ? '保存联系人修改' : '新增联系人'}</button>
                        <small className="muted">联系人输入完成并离开输入框后会自动保存。</small>
                    </div>
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

function emptyContact() {
    return { id: '', name: '', phone: '' };
}

function saveSignature(customer, customerDraft, contactDraft) {
    return JSON.stringify({ customerId: customer?.id || '', customerDraft, contactDraft });
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
