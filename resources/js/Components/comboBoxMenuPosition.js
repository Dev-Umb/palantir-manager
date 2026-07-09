const MENU_GAP = 6;
const VIEWPORT_MARGIN = 18;
const MAX_OPTIONS_HEIGHT = 240;

export function comboMenuStyleFromRect(rect, viewportHeight = typeof window !== 'undefined' ? window.innerHeight : 0) {
    const availableHeight = Math.max(0, viewportHeight - rect.bottom - MENU_GAP - VIEWPORT_MARGIN);

    return {
        position: 'fixed',
        zIndex: 100,
        top: rect.bottom + MENU_GAP,
        left: rect.left,
        right: 'auto',
        width: rect.width,
        '--combo-menu-max-height': `${Math.min(MAX_OPTIONS_HEIGHT, availableHeight)}px`,
    };
}

function containsComboTarget(root, menu, target) {
    return Boolean(target && (root?.contains(target) || menu?.contains(target)));
}

export function shouldCloseComboForFocus(root, menu, nextFocus) {
    return Boolean(nextFocus && !containsComboTarget(root, menu, nextFocus));
}

export function shouldCloseComboForPointer(root, menu, target) {
    return !containsComboTarget(root, menu, target);
}
