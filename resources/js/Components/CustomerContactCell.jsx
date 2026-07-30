import ListSummaryCell from './ListSummaryCell';

export default function CustomerContactCell({ contacts = [], onOpen }) {
    const primaryContact = contacts[0];
    const primary = [primaryContact?.name, primaryContact?.phone].filter(Boolean).join(' · ');

    return <ListSummaryCell primary={primary} count={contacts.length} onOpen={onOpen} />;
}
