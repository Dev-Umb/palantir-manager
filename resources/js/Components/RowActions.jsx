import { MoreHorizontal } from 'lucide-react';

export default function RowActions({ primary, secondary = [], menuLabel = '更多操作' }) {
    return (
        <div className="row-actions">
            {primary}
            {secondary.length > 0 && (
                <details className="row-actions-menu">
                    <summary aria-label={menuLabel}><MoreHorizontal size={16} /></summary>
                    <div>{secondary}</div>
                </details>
            )}
        </div>
    );
}
