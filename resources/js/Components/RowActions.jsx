import { MoreHorizontal } from 'lucide-react';
import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { rowActionsMenuStyleFromRects } from './rowActionsMenuPosition';

export default function RowActions({ primary, secondary = [], menuLabel = '更多操作' }) {
    const [open, setOpen] = useState(false);
    const [menuStyle, setMenuStyle] = useState(null);
    const menuId = useId();
    const rootRef = useRef(null);
    const triggerRef = useRef(null);
    const menuRef = useRef(null);

    const positionMenu = useCallback(() => {
        const triggerRect = triggerRef.current?.getBoundingClientRect();
        const menuRect = menuRef.current?.getBoundingClientRect();
        if (triggerRect && menuRect) {
            setMenuStyle(rowActionsMenuStyleFromRects(triggerRect, menuRect));
        }
    }, []);

    useLayoutEffect(() => {
        if (!open) return undefined;

        positionMenu();
        window.addEventListener('resize', positionMenu);
        window.addEventListener('scroll', positionMenu, true);

        return () => {
            window.removeEventListener('resize', positionMenu);
            window.removeEventListener('scroll', positionMenu, true);
        };
    }, [open, positionMenu]);

    useEffect(() => {
        if (!open) return undefined;

        function closeOnPointerDown(event) {
            if (!rootRef.current?.contains(event.target) && !menuRef.current?.contains(event.target)) {
                setOpen(false);
            }
        }

        function closeOnEscape(event) {
            if (event.key === 'Escape') {
                setOpen(false);
                triggerRef.current?.focus();
            }
        }

        document.addEventListener('pointerdown', closeOnPointerDown, true);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('pointerdown', closeOnPointerDown, true);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [open]);

    const menu = open && (
        <div
            ref={menuRef}
            id={menuId}
            className="row-actions-menu-popup"
            style={menuStyle || { visibility: 'hidden' }}
            role="menu"
            onClick={() => setOpen(false)}
        >
            {secondary}
        </div>
    );

    return (
        <div className="row-actions">
            {primary}
            {secondary.length > 0 && (
                <div className="row-actions-menu" ref={rootRef}>
                    <button
                        ref={triggerRef}
                        type="button"
                        className="row-actions-menu-trigger"
                        aria-label={menuLabel}
                        aria-haspopup="menu"
                        aria-expanded={open}
                        aria-controls={open ? menuId : undefined}
                        onPointerDown={(event) => event.stopPropagation()}
                        onClick={(event) => {
                            event.stopPropagation();
                            setOpen((current) => !current);
                        }}
                    >
                        <MoreHorizontal size={16} />
                    </button>
                    {menu && (typeof document === 'undefined' ? menu : createPortal(menu, document.body))}
                </div>
            )}
        </div>
    );
}
