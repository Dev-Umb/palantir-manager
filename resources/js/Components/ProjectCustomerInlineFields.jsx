import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import MultiComboBox from './MultiComboBox';
import { useRemoteOptions } from './useRemoteOptions';

export default function ProjectCustomerInlineFields({ profile, onChange, customerOptions = {}, contactOptions = {} }) {
    const [customerMenuOpen, setCustomerMenuOpen] = useState(false);
    const [customerQuery, setCustomerQuery] = useState(profile.name || '');
    const customerItems = useMemo(
        () => uniqueItems([...(customerOptions.items || []), ...(customerOptions.selectedItems || [])]),
        [customerOptions.items, customerOptions.selectedItems],
    );
    const remoteCustomers = useRemoteOptions({
        searchUrl: customerOptions.search_url,
        query: customerQuery,
        context: customerOptions.search_context,
        initialItems: customerItems,
        enabled: customerMenuOpen,
    });
    const shownCustomers = customerOptions.search_url ? remoteCustomers.items : customerItems.filter((item) => {
        const keyword = customerQuery.trim().toLowerCase();
        return !keyword || customerName(item).toLowerCase().includes(keyword) || customerAddress(item).toLowerCase().includes(keyword);
    });
    const availableContacts = useMemo(
        () => uniqueItems([...(contactOptions.items || []), ...(contactOptions.selectedItems || [])]),
        [contactOptions.items, contactOptions.selectedItems],
    );
    const selectedContactIds = profile.contacts.filter((contact) => contact.id).map((contact) => contact.id);

    useEffect(() => {
        setCustomerQuery(profile.name || '');
    }, [profile.customer_id, profile.name]);

    function changeCustomerName(name) {
        setCustomerQuery(name);
        setCustomerMenuOpen(true);
        onChange({ ...profile, name, overwrite_confirmed: false });
    }

    function pickCustomer(item) {
        onChange({
            customer_id: item.id,
            name: customerName(item),
            address: customerAddress(item),
            level: item.meta?.level || '',
            customer_nature: item.meta?.customer_nature || '',
            overwrite_confirmed: false,
            contacts: [],
        });
        setCustomerQuery(customerName(item));
        setCustomerMenuOpen(false);
    }

    function changeExistingContacts(ids) {
        const selected = new Set(ids);
        const retained = profile.contacts.filter((contact) => !contact.id || selected.has(contact.id));
        const existingIds = new Set(retained.map((contact) => contact.id).filter(Boolean));
        const added = ids
            .filter((id) => !existingIds.has(id))
            .map((id) => {
                const item = availableContacts.find((candidate) => candidate.id === id);
                return {
                    id,
                    name: item?.meta?.name || item?.label || '',
                    phone: item?.meta?.phone || '',
                };
            });
        onChange({ ...profile, contacts: [...retained, ...added], overwrite_confirmed: false });
    }

    function changeContact(index, key, value) {
        onChange({
            ...profile,
            overwrite_confirmed: false,
            contacts: profile.contacts.map((contact, contactIndex) => (
                contactIndex === index ? { ...contact, [key]: value } : contact
            )),
        });
    }

    function addContact() {
        onChange({
            ...profile,
            overwrite_confirmed: false,
            contacts: [...profile.contacts, { id: '', name: '', phone: '' }],
        });
    }

    function removeContact(index) {
        onChange({
            ...profile,
            overwrite_confirmed: false,
            contacts: profile.contacts.filter((_, contactIndex) => contactIndex !== index),
        });
    }

    return (
        <fieldset className="project-customer-inline wide">
            <legend>客户与联系人资料</legend>
            <p className="muted wide">填写后随项目一次保存并同步到客户主档；客户按名称和地址识别。</p>
            <label>
                <span>客户名称<b>*</b></span>
                <div className="creatable-combo">
                    <input
                        value={customerQuery}
                        required
                        onChange={(event) => changeCustomerName(event.target.value)}
                        onFocus={() => setCustomerMenuOpen(true)}
                        onBlur={() => window.setTimeout(() => setCustomerMenuOpen(false), 0)}
                        placeholder="搜索已有客户，或直接输入新客户名称"
                    />
                    {customerMenuOpen && (
                        <div className="multi-combo-menu creatable-combo-menu">
                            {shownCustomers.map((item) => (
                                <button
                                    key={item.id}
                                    type="button"
                                    className="combo-option customer-profile-option"
                                    onMouseDown={(event) => event.preventDefault()}
                                    onClick={() => pickCustomer(item)}
                                >
                                    <span>{customerName(item)}</span>
                                    <small>{customerAddress(item) || '地址未填写'}</small>
                                </button>
                            ))}
                            {remoteCustomers.loading && <p className="combo-empty">正在搜索...</p>}
                            {remoteCustomers.failed && <p className="combo-empty" role="alert">搜索失败，可继续直接填写</p>}
                            {!remoteCustomers.loading && !remoteCustomers.failed && shownCustomers.length === 0 && (
                                <p className="combo-empty">没有匹配客户，保存后将新建</p>
                            )}
                        </div>
                    )}
                </div>
            </label>
            <label className="wide">
                <span>客户地址</span>
                <input value={profile.address} onChange={(event) => onChange({ ...profile, address: event.target.value, overwrite_confirmed: false })} />
            </label>
            <label>
                <span>客户等级</span>
                <select value={profile.level} onChange={(event) => onChange({ ...profile, level: event.target.value, overwrite_confirmed: false })}>
                    <option value="">未选择</option>
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="C">C</option>
                </select>
            </label>
            <label>
                <span>客户性质</span>
                <select value={profile.customer_nature} onChange={(event) => onChange({ ...profile, customer_nature: event.target.value, overwrite_confirmed: false })}>
                    <option value="">未选择</option>
                    <option value="国央企">国央企</option>
                    <option value="私企">私企</option>
                </select>
            </label>
            <div className="wide project-contact-selector">
                <span>从该客户已有联系人中选择</span>
                <MultiComboBox
                    value={selectedContactIds}
                    items={availableContacts}
                    selectedItems={contactOptions.selectedItems || []}
                    searchUrl={contactOptions.search_url}
                    searchContext={contactOptions.search_context}
                    onChange={changeExistingContacts}
                />
            </div>
            <div className="wide project-contact-rows">
                <div className="project-contact-rows-head">
                    <strong>客户联系人</strong>
                    <button type="button" className="secondary-button small-action" onClick={addContact}><Plus size={14} /> 添加联系人行</button>
                </div>
                {profile.contacts.length === 0 && <p className="muted">暂无联系人，可从下拉选择或添加联系人行。</p>}
                {profile.contacts.map((contact, index) => (
                    <div className="project-contact-row" key={contact.id || `new-contact-${index}`}>
                        <label>
                            <span>联系人姓名*</span>
                            <input value={contact.name} required onChange={(event) => changeContact(index, 'name', event.target.value)} />
                        </label>
                        <label>
                            <span>手机号</span>
                            <input value={contact.phone} onChange={(event) => changeContact(index, 'phone', event.target.value)} />
                        </label>
                        <button type="button" className="icon-link" aria-label={`移除联系人 ${contact.name || index + 1}`} onClick={() => removeContact(index)}>
                            <Trash2 size={15} />
                        </button>
                    </div>
                ))}
            </div>
        </fieldset>
    );
}

export function CustomerProfileConflictDialog({ conflicts, onCancel, onConfirm, processing = false }) {
    return (
        <div className="customer-conflict-backdrop" role="dialog" aria-modal="true" aria-label="客户资料冲突">
            <section className="customer-conflict-dialog">
                <h3>客户资料存在冲突</h3>
                <p>以下内容与客户主档不同。确认后将覆盖共享客户资料，并影响关联该客户的其他项目。</p>
                <dl>
                    {conflicts.map((conflict) => (
                        <div key={conflict.field}>
                            <dt>{conflict.label}</dt>
                            <dd><span>{conflict.current || '未填写'}</span><strong>→</strong><span>{conflict.submitted || '未填写'}</span></dd>
                        </div>
                    ))}
                </dl>
                <div className="form-actions">
                    <button type="button" className="secondary-button" onClick={onCancel} disabled={processing}>取消，返回修改</button>
                    <button type="button" onClick={onConfirm} disabled={processing}>{processing ? '正在保存...' : '确认覆盖并保存'}</button>
                </div>
            </section>
        </div>
    );
}

export async function previewProjectCustomerProfile(profile) {
    const response = await fetch('/project-customer-profile/preview', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify(normalizedProjectCustomerProfile(profile)),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || '客户资料检查失败。');
    }

    return data;
}

export function projectCustomerProfile(payload = {}, record = null, relationOptions = {}) {
    const customerId = payload.customer_id || '';
    const option = [...(relationOptions.customer_id?.items || []), ...(relationOptions.customer_id?.selectedItems || [])]
        .find((item) => item.id === customerId);
    const customer = record?.customer || (option ? {
        id: option.id,
        name: customerName(option),
        address: customerAddress(option),
        level: option.meta?.level || '',
        customer_nature: option.meta?.customer_nature || '',
    } : null);
    const contacts = record?.contacts || (payload.customer_contact_ids || []).map((id) => {
        const item = [...(relationOptions.customer_contact_ids?.items || []), ...(relationOptions.customer_contact_ids?.selectedItems || [])]
            .find((candidate) => candidate.id === id);
        return { id, name: item?.meta?.name || item?.label || '', phone: item?.meta?.phone || '' };
    });

    return {
        customer_id: customer?.id || customerId,
        name: customer?.name || '',
        address: customer?.address || '',
        level: customer?.level || '',
        customer_nature: customer?.customer_nature || '',
        overwrite_confirmed: false,
        contacts,
    };
}

export function normalizedProjectCustomerProfile(profile, overwriteConfirmed = false) {
    return {
        customer_id: profile.customer_id || null,
        name: String(profile.name || '').trim(),
        address: String(profile.address || '').trim(),
        level: profile.level || '',
        customer_nature: profile.customer_nature || '',
        overwrite_confirmed: overwriteConfirmed,
        contacts: (profile.contacts || [])
            .filter((contact) => String(contact.name || '').trim() !== '')
            .map((contact) => ({
                id: contact.id || null,
                name: String(contact.name).trim(),
                phone: String(contact.phone || '').trim(),
            })),
    };
}

function customerName(item) {
    return item.meta?.name || item.title || item.label || '';
}

function customerAddress(item) {
    return item.meta?.address || '';
}

function uniqueItems(items) {
    return items.filter((item, index, all) => item?.id && all.findIndex((candidate) => candidate.id === item.id) === index);
}
