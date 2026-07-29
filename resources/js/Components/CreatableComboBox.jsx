import { useEffect, useMemo, useState } from 'react';
import { useRemoteOptions } from './useRemoteOptions';

export default function CreatableComboBox({ value, items = [], selectedItems = [], onChange, autoFocus = false, searchUrl = '', searchContext = {} }) {
    const selected = [...items, ...selectedItems].find((item) => item.id === value);
    const [draft, setDraft] = useState(selected?.label || (typeof value === 'string' ? value : ''));
    const [open, setOpen] = useState(false);
    const [activeIndex, setActiveIndex] = useState(-1);
    const remote = useRemoteOptions({
        searchUrl,
        query: draft,
        context: searchContext,
        initialItems: items,
        enabled: open,
    });
    const available = useMemo(() => {
        const candidates = searchUrl ? remote.items : items;
        const keyword = draft.trim().toLowerCase();
        const shown = searchUrl || !keyword
            ? candidates
            : candidates.filter((item) => item.label.toLowerCase().includes(keyword));

        return shown.filter((item, index, all) => all.findIndex((candidate) => candidate.id === item.id) === index);
    }, [draft, items, remote.items, searchUrl]);

    useEffect(() => {
        const current = [...remote.items, ...items, ...selectedItems].find((item) => item.id === value);
        setDraft(current?.label || (typeof value === 'string' ? value : ''));
    }, [items, remote.items, selectedItems, value]);

    useEffect(() => {
        setActiveIndex(-1);
    }, [draft, remote.items]);

    function change(next) {
        setDraft(next);
        setOpen(true);
        const exact = [...remote.items, ...items].find((item) => item.label.trim() === next.trim());
        onChange(exact?.id || next);
    }

    function pick(item) {
        setDraft(item.label);
        setOpen(false);
        onChange(item.id);
    }

    function navigate(event) {
        if (event.key === 'Escape') {
            setOpen(false);
            return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) return;
        if (!open || !available.length) {
            if (event.key !== 'Enter') setOpen(true);
            return;
        }

        event.preventDefault();
        if (event.key === 'ArrowDown') {
            setActiveIndex((current) => Math.min(current + 1, available.length - 1));
        } else if (event.key === 'ArrowUp') {
            setActiveIndex((current) => current <= 0 ? available.length - 1 : current - 1);
        } else if (activeIndex >= 0) {
            pick(available[activeIndex]);
        }
    }

    return (
        <div className="creatable-combo">
            <input
                value={draft}
                onChange={(event) => change(event.target.value)}
                onKeyDown={navigate}
                onFocus={() => setOpen(true)}
                onBlur={() => window.setTimeout(() => setOpen(false), 0)}
                placeholder="搜索已有物资，或直接输入新名称"
                autoFocus={autoFocus}
            />
            {open && (
                <div className="multi-combo-menu creatable-combo-menu">
                    {available.map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            className={`combo-option ${available[activeIndex]?.id === item.id ? 'active' : ''}`}
                            onMouseEnter={() => setActiveIndex(available.findIndex((candidate) => candidate.id === item.id))}
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={() => pick(item)}
                        >
                            <span>{item.label}</span>
                        </button>
                    ))}
                    {remote.loading && <p className="combo-empty">正在搜索...</p>}
                    {remote.failed && <p className="combo-empty" role="alert">搜索失败，可直接填写新物资</p>}
                    {!remote.loading && !remote.failed && !available.length && (
                        <p className="combo-empty">无匹配物资，保存后将新建</p>
                    )}
                </div>
            )}
        </div>
    );
}
