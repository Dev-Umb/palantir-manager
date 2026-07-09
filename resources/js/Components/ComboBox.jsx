import { Check, ChevronDown, Search } from 'lucide-react';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { comboMenuStyleFromRect, shouldCloseComboForFocus, shouldCloseComboForPointer } from './comboBoxMenuPosition';

export default function ComboBox({ value, options, onChange, placeholder = '未选择', autoFocus = false, startOpen = false }) {
    const [open, setOpen] = useState(startOpen);
    const [query, setQuery] = useState('');
    const [menuStyle, setMenuStyle] = useState(null);
    const rootRef = useRef(null);
    const buttonRef = useRef(null);
    const menuRef = useRef(null);
    const selected = options.find((option) => option.value === value);
    const shown = useMemo(() => {
        const keyword = query.trim().toLowerCase();
        if (!keyword) return options;

        return options.filter((option) => option.label.toLowerCase().includes(keyword));
    }, [options, query]);

    useEffect(() => {
        if (autoFocus) buttonRef.current?.focus();
    }, [autoFocus]);

    const positionMenu = useCallback(() => {
        const rect = buttonRef.current?.getBoundingClientRect();
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
                setOpen(false);
                setQuery('');
            }
        }

        document.addEventListener('pointerdown', closeOnPointerDown, true);
        return () => document.removeEventListener('pointerdown', closeOnPointerDown, true);
    }, [open]);

    function close(event) {
        const nextFocus = event.relatedTarget;
        window.setTimeout(() => {
            if (shouldCloseComboForFocus(rootRef.current, menuRef.current, nextFocus || document.activeElement)) {
                setOpen(false);
                setQuery('');
            }
        }, 0);
    }

    function pick(next) {
        onChange(next);
        setOpen(false);
        setQuery('');
        buttonRef.current?.focus();
    }

    const menu = open && (
        <div ref={menuRef} className="combo-menu" style={menuStyle || undefined} onBlur={close}>
            <div className="combo-search">
                <Search size={14} />
                <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="输入关键字搜索" autoFocus />
            </div>
            <div className="combo-options">
                {shown.map((option) => (
                    <button key={option.value || '__empty'} type="button" className="combo-option" onMouseDown={(event) => event.preventDefault()} onClick={() => pick(option.value)}>
                        <span>{option.label}</span>
                        {option.value === value && <Check size={14} />}
                    </button>
                ))}
                {!shown.length && <p className="combo-empty">没有匹配项</p>}
            </div>
        </div>
    );

    return (
        <div className="combo" ref={rootRef} onBlur={close}>
            <button ref={buttonRef} type="button" className="combo-trigger" onClick={() => setOpen(!open)}>
                <span className={selected ? '' : 'placeholder'}>{selected?.label || placeholder}</span>
                <ChevronDown size={15} />
            </button>
            {menu && (typeof document === 'undefined' ? menu : createPortal(menu, document.body))}
        </div>
    );
}
