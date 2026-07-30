import { useEffect } from 'react';

const FOCUSABLE_SELECTOR = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

export function useDialogFocus(active, panelRef) {
    useEffect(() => {
        if (!active || !panelRef.current) return undefined;

        const panel = panelRef.current;
        const previousFocus = document.activeElement;
        const focusable = () => [...panel.querySelectorAll(FOCUSABLE_SELECTOR)];
        const initialTarget = panel.querySelector('[autofocus]') || focusable()[0] || panel;

        initialTarget.focus();

        function trapFocus(event) {
            if (event.key !== 'Tab') return;

            const elements = focusable();
            if (!elements.length) {
                event.preventDefault();
                panel.focus();
                return;
            }

            const first = elements[0];
            const last = elements[elements.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }

        panel.addEventListener('keydown', trapFocus);

        return () => {
            panel.removeEventListener('keydown', trapFocus);
            if (previousFocus instanceof HTMLElement) previousFocus.focus();
        };
    }, [active, panelRef]);
}
