import { Check, ChevronDown, Search } from 'lucide-react';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { comboMenuStyleFromRect, shouldCloseComboForPointer } from './comboBoxMenuPosition';
import { useRemoteOptions } from './useRemoteOptions';

export default function MultiComboBox({ value = [], items = [], selectedItems = [], onChange, onClose, startOpen = false, searchUrl = '', searchContext = {}, searchPlaceholder = '输入联系人姓名或手机号' }) {
    const [open, setOpen] = useState(startOpen);
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(-1);
    const [menuStyle, setMenuStyle] = useState(null);
    const rootRef = useRef(null);
    const triggerRef = useRef(null);
    const menuRef = useRef(null);
    const remote = useRemoteOptions({
        searchUrl,
        query,
        context: searchContext,
        initialItems: items,
        enabled: open,
    });
    const availableItems = useMemo(() => {
        if (searchUrl) return remote.items;
        const keyword = query.trim().toLowerCase();

        return keyword ? items.filter((item) => item.label.toLowerCase().includes(keyword)) : items;
    }, [items, query, remote.items, searchUrl]);
    const selected = new Set(Array.isArray(value) ? value : []);
    const availableById = new Map(availableItems.map((item) => [item.id, item]));
    const initialById = new Map(items.map((item) => [item.id, item]));
    const historicalById = new Map(selectedItems.map((item) => [item.id, item]));
    const labels = [...selected].map((id) => availableById.get(id)?.label || initialById.get(id)?.label || historicalById.get(id)?.label || id);
    const historicalSelected = [...selected]
        .filter((id) => !availableById.has(id))
        .map((id) => historicalById.get(id) || initialById.get(id) || { id, label: id });

    function closeMenu() {
        setOpen(false);
        setQuery('');
        onClose?.();
    }

    const positionMenu = useCallback(() => {
        const rect = triggerRef.current?.getBoundingClientRect();
        if (rect) setMenuStyle(comboMenuStyleFromRect(rect));
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
            if (shouldCloseComboForPointer(rootRef.current, menuRef.current, event.target)) {
                closeMenu();
            }
        }

        document.addEventListener('pointerdown', closeOnPointerDown, true);

        return () => document.removeEventListener('pointerdown', closeOnPointerDown, true);
    }, [open]);

    function toggle(id) {
        const next = new Set(selected);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        onChange([...next]);
    }

    function navigate(event) {
        if (event.key === 'Escape') {
            closeMenu();
            return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key) || !availableItems.length) return;

        event.preventDefault();
        if (event.key === 'ArrowDown') {
            setActiveIndex((current) => Math.min(current + 1, availableItems.length - 1));
        } else if (event.key === 'ArrowUp') {
            setActiveIndex((current) => current <= 0 ? availableItems.length - 1 : current - 1);
        } else if (activeIndex >= 0) {
            toggle(availableItems[activeIndex].id);
        }
    }

    const menu = open && (
        <div
            ref={menuRef}
            className="multi-combo-menu ag-custom-component-popup"
            style={menuStyle || undefined}
        >
            <div className="combo-search">
                <Search size={14} />
                <input
                    value={query}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setActiveIndex(-1);
                    }}
                    onKeyDown={navigate}
                    placeholder={searchPlaceholder}
                    autoFocus
                />
            </div>
            <div className="combo-options">
                {availableItems.map((item) => (
                    <button
                        type="button"
                        className={`combo-option ${availableItems[activeIndex]?.id === item.id ? 'active' : ''}`}
                        key={item.id}
                        onMouseEnter={() => setActiveIndex(availableItems.findIndex((candidate) => candidate.id === item.id))}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => toggle(item.id)}
                    >
                        <span>{item.label}</span>
                        {selected.has(item.id) && <Check size={14} />}
                    </button>
                ))}
                {historicalSelected.map((item) => (
                    <button type="button" className="combo-option" key={item.id} onMouseDown={(event) => event.preventDefault()} onClick={() => toggle(item.id)}>
                        <span>{item.label}（历史关联）</span>
                        <Check size={14} />
                    </button>
                ))}
                {remote.loading && <p className="combo-empty">正在搜索...</p>}
                {remote.failed && <p className="combo-empty" role="alert">搜索失败，请重试</p>}
                {!remote.loading && !remote.failed && !availableItems.length && !historicalSelected.length && <p className="combo-empty">没有可选项</p>}
            </div>
            <button type="button" className="multi-combo-done" onMouseDown={(event) => event.preventDefault()} onClick={closeMenu}>
                完成选择
            </button>
        </div>
    );

    return (
        <div className="multi-combo" ref={rootRef}>
            <button
                ref={triggerRef}
                type="button"
                className="combo-trigger"
                onClick={() => {
                    if (open) closeMenu();
                    else setOpen(true);
                }}
            >
                <span className={labels.length ? '' : 'placeholder'}>{labels.length ? labels.join('、') : '未选择'}</span>
                <ChevronDown size={15} />
            </button>
            {menu && (typeof document === 'undefined' ? menu : createPortal(menu, document.body))}
        </div>
    );
}
