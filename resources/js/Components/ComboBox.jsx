import { Check, ChevronDown, Search } from 'lucide-react';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { comboMenuStyleFromRect, shouldCloseComboForFocus, shouldCloseComboForPointer } from './comboBoxMenuPosition';
import { useRemoteOptions } from './useRemoteOptions';

export default function ComboBox({ value, options = [], onChange, placeholder = '未选择', autoFocus = false, startOpen = false, searchUrl = '', searchContext = {} }) {
    const [open, setOpen] = useState(startOpen);
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(-1);
    const [menuStyle, setMenuStyle] = useState(null);
    const rootRef = useRef(null);
    const buttonRef = useRef(null);
    const menuRef = useRef(null);
    const initialRemoteItems = useMemo(() => options.map((option) => ({
        ...option,
        id: option.id ?? option.value,
    })), [options]);
    const remote = useRemoteOptions({
        searchUrl,
        query,
        context: searchContext,
        initialItems: initialRemoteItems,
        enabled: open,
    });
    const activeOptions = useMemo(() => {
        if (!searchUrl) return options;

        const empty = options.filter((option) => option.value === '');
        const fetched = remote.items
            .map((option) => ({ value: option.value ?? option.id, label: option.label }))
            .filter((option) => option.value !== '');

        return [...empty, ...fetched.filter((option, index, all) => (
            all.findIndex((candidate) => candidate.value === option.value) === index
        ))];
    }, [options, remote.items, searchUrl]);
    const selected = activeOptions.find((option) => option.value === value)
        || options.find((option) => option.value === value);
    const shown = useMemo(() => {
        if (searchUrl) return activeOptions;
        const keyword = query.trim().toLowerCase();
        if (!keyword) return activeOptions;

        return activeOptions.filter((option) => option.label.toLowerCase().includes(keyword));
    }, [activeOptions, query, searchUrl]);

    useEffect(() => {
        if (autoFocus) buttonRef.current?.focus();
    }, [autoFocus]);

    useEffect(() => {
        setActiveIndex(-1);
    }, [query, remote.items]);

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

    function navigate(event) {
        if (event.key === 'Escape') {
            setOpen(false);
            setQuery('');
            buttonRef.current?.focus();
            return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key) || !shown.length) return;

        event.preventDefault();
        if (event.key === 'ArrowDown') {
            setActiveIndex((current) => Math.min(current + 1, shown.length - 1));
        } else if (event.key === 'ArrowUp') {
            setActiveIndex((current) => current <= 0 ? shown.length - 1 : current - 1);
        } else if (activeIndex >= 0) {
            pick(shown[activeIndex].value);
        }
    }

    const menu = open && (
        <div ref={menuRef} className="combo-menu" style={menuStyle || undefined} onBlur={close}>
            <div className="combo-search">
                <Search size={14} />
                <input value={query} onChange={(event) => setQuery(event.target.value)} onKeyDown={navigate} placeholder="输入关键字搜索" autoFocus />
            </div>
            <div className="combo-options">
                {shown.map((option) => (
                    <button
                        key={option.value || '__empty'}
                        type="button"
                        className={`combo-option ${shown[activeIndex]?.value === option.value ? 'active' : ''}`}
                        onMouseEnter={() => setActiveIndex(shown.findIndex((candidate) => candidate.value === option.value))}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => pick(option.value)}
                    >
                        <span>{option.label}</span>
                        {option.value === value && <Check size={14} />}
                    </button>
                ))}
                {remote.loading && <p className="combo-empty">正在搜索...</p>}
                {remote.failed && <p className="combo-empty" role="alert">搜索失败，请重试</p>}
                {!remote.loading && !remote.failed && !shown.length && <p className="combo-empty">没有匹配项</p>}
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
