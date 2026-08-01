import { Plus } from 'lucide-react';
import ListSummaryCell from './ListSummaryCell';

export default function CustomerContactCell({ contacts = [], customerName = '当前客户', canCreate = false, onOpen, onCreate }) {
    const primaryContact = contacts[0];
    const primary = [primaryContact?.name, primaryContact?.phone].filter(Boolean).join(' · ');

    return (
        <div className="customer-contact-cell">
            <div className="customer-contact-summary">
                <ListSummaryCell primary={primary} count={contacts.length} onOpen={onOpen} />
            </div>
            {canCreate && (
                <button
                    type="button"
                    className="customer-contact-create"
                    aria-label={`为${customerName}新增联系人`}
                    title="新增联系人"
                    onClick={(event) => {
                        event.stopPropagation();
                        onCreate?.();
                    }}
                >
                    <Plus size={15} />
                </button>
            )}
        </div>
    );
}
