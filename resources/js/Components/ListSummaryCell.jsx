export default function ListSummaryCell({ primary, count = 0, onOpen, empty = '—' }) {
    const text = String(primary ?? '').trim();

    if (!text) {
        return <span className="empty-value list-summary-empty">{empty}</span>;
    }

    return (
        <button
            type="button"
            className="list-summary-trigger"
            title={text}
            aria-label={`${text}，共 ${count} 项`}
            onClick={(event) => {
                event.stopPropagation();
                onOpen?.();
            }}
        >
            <span className="list-summary-primary">{text}</span>
            {count > 1 && <span className="list-summary-count">+{count - 1}</span>}
        </button>
    );
}
