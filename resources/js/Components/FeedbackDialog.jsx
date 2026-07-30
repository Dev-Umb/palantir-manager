import { AlertCircle, X } from 'lucide-react';
import { useEffect, useId, useRef } from 'react';
import { useDialogFocus } from './useDialogFocus';

export default function FeedbackDialog({ title = '操作失败', messages = [], onClose }) {
    const titleId = useId();
    const panelRef = useRef(null);
    const normalizedMessages = (Array.isArray(messages) ? messages : [messages]).filter(Boolean);
    useDialogFocus(normalizedMessages.length > 0, panelRef);

    useEffect(() => {
        if (!normalizedMessages.length) return undefined;

        function closeOnEscape(event) {
            if (event.key === 'Escape') onClose?.();
        }

        document.addEventListener('keydown', closeOnEscape);

        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [normalizedMessages.length, onClose]);

    if (!normalizedMessages.length) return null;

    return (
        <div className="feedback-dialog-backdrop" role="alertdialog" aria-modal="true" aria-labelledby={titleId}>
            <section ref={panelRef} className="feedback-dialog-panel" tabIndex={-1}>
                <div className="feedback-dialog-icon" aria-hidden="true">
                    <AlertCircle size={22} />
                </div>
                <div className="feedback-dialog-content">
                    <h2 id={titleId}>{title}</h2>
                    {normalizedMessages.map((message, index) => (
                        <p key={`${message}-${index}`}>{message}</p>
                    ))}
                </div>
                <button type="button" className="feedback-dialog-close" aria-label="关闭错误提示" onClick={onClose}>
                    <X size={17} />
                </button>
                <button type="button" className="feedback-dialog-confirm" onClick={onClose}>我知道了</button>
            </section>
        </div>
    );
}
