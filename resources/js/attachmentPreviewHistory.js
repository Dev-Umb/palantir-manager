let activePreview = null;

// Register before Inertia starts: its popstate handler remounts the page and resets unsaved forms.
if (typeof window !== 'undefined') {
    window.addEventListener('popstate', (event) => {
        const preview = activePreview;
        if (!preview) return;
        activePreview = null;
        if (window.location.href === preview.url) event.stopImmediatePropagation();
        preview.onClose();
    });
}

export function beginAttachmentPreview(onClose) {
    const previousState = window.history.state;
    const preview = { url: window.location.href, onClose, marker: `attachment-${Date.now()}-${Math.random()}` };
    activePreview = preview;
    window.history.pushState({ ...previousState, attachmentPreview: preview.marker }, '');
    return {
        close() {
            if (activePreview === preview && window.location.href === preview.url) window.history.back();
            else onClose();
        },
        dispose() {
            if (activePreview === preview) activePreview = null;
            if (window.history.state?.attachmentPreview === preview.marker) window.history.replaceState(previousState, '');
        },
    };
}
