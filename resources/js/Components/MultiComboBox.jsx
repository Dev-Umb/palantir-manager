import { Check, ChevronDown, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useRemoteOptions } from './useRemoteOptions';

export default function MultiComboBox({ value = [], items = [], selectedItems = [], onChange, searchUrl = '', searchContext = {} }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(-1);
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

    function toggle(id) {
        const next = new Set(selected);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        onChange([...next]);
    }

    function navigate(event) {
        if (event.key === 'Escape') {
            setOpen(false);
            setQuery('');
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

    return (
        <div className="multi-combo">
            <button type="button" className="combo-trigger" onClick={() => setOpen((current) => !current)}>
                <span className={labels.length ? '' : 'placeholder'}>{labels.length ? labels.join('、') : '未选择'}</span>
                <ChevronDown size={15} />
            </button>
            {open && (
                <div className="multi-combo-menu">
                    <div className="combo-search">
                        <Search size={14} />
                        <input
                            value={query}
                            onChange={(event) => {
                                setQuery(event.target.value);
                                setActiveIndex(-1);
                            }}
                            onKeyDown={navigate}
                            placeholder="输入联系人姓名或手机号"
                            autoFocus
                        />
                    </div>
                    {availableItems.map((item) => (
                        <button
                            type="button"
                            className={`combo-option ${availableItems[activeIndex]?.id === item.id ? 'active' : ''}`}
                            key={item.id}
                            onMouseEnter={() => setActiveIndex(availableItems.findIndex((candidate) => candidate.id === item.id))}
                            onClick={() => toggle(item.id)}
                        >
                            <span>{item.label}</span>
                            {selected.has(item.id) && <Check size={14} />}
                        </button>
                    ))}
                    {historicalSelected.map((item) => (
                        <button type="button" className="combo-option" key={item.id} onClick={() => toggle(item.id)}>
                            <span>{item.label}（历史关联）</span>
                            <Check size={14} />
                        </button>
                    ))}
                    {remote.loading && <p className="combo-empty">正在搜索...</p>}
                    {remote.failed && <p className="combo-empty" role="alert">搜索失败，请重试</p>}
                    {!remote.loading && !remote.failed && !availableItems.length && !historicalSelected.length && <p className="combo-empty">没有可选项</p>}
                </div>
            )}
        </div>
    );
}
