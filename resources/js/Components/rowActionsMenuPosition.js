const MENU_GAP = 6;
const VIEWPORT_MARGIN = 12;

export function rowActionsMenuStyleFromRects(
    triggerRect,
    menuRect,
    viewport = {
        width: typeof window !== 'undefined' ? window.innerWidth : 0,
        height: typeof window !== 'undefined' ? window.innerHeight : 0,
    },
) {
    const availableBelow = viewport.height - triggerRect.bottom - MENU_GAP - VIEWPORT_MARGIN;
    const availableAbove = triggerRect.top - MENU_GAP - VIEWPORT_MARGIN;
    const openAbove = menuRect.height > availableBelow && availableAbove > availableBelow;
    const top = openAbove
        ? Math.max(VIEWPORT_MARGIN, triggerRect.top - menuRect.height - MENU_GAP)
        : Math.min(triggerRect.bottom + MENU_GAP, viewport.height - menuRect.height - VIEWPORT_MARGIN);
    const left = Math.min(
        Math.max(VIEWPORT_MARGIN, triggerRect.right - menuRect.width),
        Math.max(VIEWPORT_MARGIN, viewport.width - menuRect.width - VIEWPORT_MARGIN),
    );

    return {
        position: 'fixed',
        zIndex: 120,
        top,
        left,
    };
}
