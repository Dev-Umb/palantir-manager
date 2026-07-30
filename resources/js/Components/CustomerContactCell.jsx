import { Eye, Plus } from 'lucide-react';

export default function CustomerContactCell({ contacts = [], canCreate = false, onDetail, onCreate }) {
    return (
        <div className="customer-contact-cell">
            {contacts.length > 0 ? (
                <ul className="customer-contact-list" aria-label="客户联系人">
                    {contacts.map((contact) => (
                        <li key={contact.id} className="customer-contact-item">
                            <span className="customer-contact-name">{contact.name || '未命名联系人'}</span>
                            <span className="customer-contact-phone">{contact.phone || '未填写电话'}</span>
                            <button
                                type="button"
                                className="customer-contact-detail"
                                aria-label={`查看${contact.name || '联系人'}详情`}
                                title="查看详情"
                                onClick={() => onDetail?.(contact)}
                            >
                                <Eye size={14} />
                            </button>
                        </li>
                    ))}
                </ul>
            ) : (
                <span className="customer-contact-empty">暂无联系人</span>
            )}
            {canCreate && (
                <button type="button" className="customer-contact-add" onClick={() => onCreate?.()}>
                    <Plus size={13} /> 新增联系人
                </button>
            )}
        </div>
    );
}
