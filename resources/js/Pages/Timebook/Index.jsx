import { Head, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Layout from '../../Components/Layout';
import { useDialogFocus } from '../../Components/useDialogFocus';
import './timebook.css';

export function localDate(date = new Date()) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

const blankEntry = () => ({ name: '', day: localDate(), days: 1, overtime: 0, project: '', note: '' });
const display = (value) => Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 2 });

async function request(url, method = 'GET', body) {
    const response = await fetch(url, {
        method, credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    if ([401, 419].includes(response.status) || response.redirected) {
        throw new Error('登录已失效，请在新标签页重新登录后重试；当前输入已保留。');
    }
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        const error = new Error(Object.values(data.errors || {}).flat().join('；') || data.message || '请求失败，请重试。');
        error.existing = data.existing;
        throw error;
    }
    return response;
}

function Modal({ title, children, onClose }) {
    const panel = useRef(null);
    useDialogFocus(true, panel);
    return <div className="timebook-modal" role="dialog" aria-modal="true" aria-label={title}
        onKeyDown={(event) => { if (event.key === 'Escape') onClose(); }}>
        <section ref={panel} tabIndex={-1}><h2>{title}</h2>{children}<button type="button" onClick={onClose}>关闭</button></section>
    </div>;
}

export default function Index({ initial, urls }) {
    const permissions = usePage().props.auth?.permissions || [];
    const can = (action) => permissions.includes(`timebook.${action}`);
    const [data, setData] = useState(initial);
    const [filters, setFilters] = useState(initial.filters);
    const [entry, setEntry] = useState(blankEntry);
    const [editing, setEditing] = useState(null);
    const [suggestions, setSuggestions] = useState([]);
    const [suggestionError, setSuggestionError] = useState('');
    const [view, setView] = useState('records');
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [exporting, setExporting] = useState(false);
    const [queryValid, setQueryValid] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [duplicate, setDuplicate] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [undo, setUndo] = useState(null);
    const [history, setHistory] = useState(null);
    const loadSequence = useRef(0);
    const mutationLock = useRef(false);
    const nameInput = useRef(null);
    const unapplied = JSON.stringify(filters) !== JSON.stringify(data.filters);

    useEffect(() => {
        let cancelled = false;
        setSuggestions([]);
        const timer = setTimeout(async () => {
            try {
                const response = await request(`${urls.names}?${new URLSearchParams({ q: entry.name })}`);
                const names = await response.json();
                if (!cancelled) { setSuggestions(names); setSuggestionError(''); }
            } catch (failure) {
                if (!cancelled) setSuggestionError(`姓名提示加载失败，可继续手动输入。${failure.message}`);
            }
        }, 120);
        return () => { cancelled = true; clearTimeout(timer); };
    }, [entry.name, urls.names]);

    useEffect(() => {
        if (!undo) return undefined;
        const timer = setTimeout(() => setUndo(null), 15000);
        return () => clearTimeout(timer);
    }, [undo]);

    async function load(next = data.filters, page = 1) {
        const sequence = ++loadSequence.current;
        setLoading(true); setQueryValid(false); setError('');
        try {
            const response = await request(`${urls.records}?${new URLSearchParams({ ...next, page })}`);
            const result = await response.json();
            if (sequence === loadSequence.current) { setData(result); setQueryValid(true); }
        } catch (failure) {
            if (sequence === loadSequence.current) setError(failure.message);
        } finally {
            if (sequence === loadSequence.current) setLoading(false);
        }
    }

    function reset() {
        setEntry((old) => ({ ...blankEntry(), day: old.day, project: old.project }));
        setEditing(null); setDuplicate(null);
    }

    function edit(row) {
        setEditing(row.id); setEntry({ name: row.name, day: row.day, days: row.days, overtime: row.overtime,
            project: row.project, note: row.note, version: row.version });
        setDuplicate(null); nameInput.current?.focus();
    }

    async function save(event) {
        event.preventDefault();
        if (mutationLock.current) return;
        mutationLock.current = true; setBusy(true); setError(''); setNotice(''); setDuplicate(null);
        try {
            await request(editing ? `${urls.entries}/${editing}` : urls.entries, editing ? 'PUT' : 'POST',
                { ...entry, days: Number(entry.days), overtime: Number(entry.overtime) });
            setNotice('记工已保存。'); reset();
            await load();
        } catch (failure) {
            setError(failure.message); setDuplicate(failure.existing || null);
        } finally { mutationLock.current = false; setBusy(false); }
    }

    async function changeDeleted(row, restore = false) {
        if (mutationLock.current) return;
        mutationLock.current = true; setBusy(true); setError(''); setNotice('');
        try {
            const response = await request(`${urls.entries}/${row.id}${restore ? '/restore' : ''}`, restore ? 'POST' : 'DELETE', { version: row.version });
            const changed = await response.json();
            setDeleting(null); setUndo(restore ? null : changed);
            if (editing === row.id) reset();
            setNotice(restore ? '记工已恢复。' : '记工已删除，可在 15 秒内撤销。');
            await load();
        } catch (failure) { setError(failure.message); }
        finally { mutationLock.current = false; setBusy(false); }
    }

    function apply(next) { setFilters(next); void load(next); }
    function worker(row) { apply({ ...data.filters, worker: String(row.worker_id) }); }
    function thisMonth() {
        const now = new Date();
        apply({ ...filters, start: localDate(new Date(now.getFullYear(), now.getMonth(), 1)),
            end: localDate(new Date(now.getFullYear(), now.getMonth() + 1, 0)) });
    }
    async function showHistory(row) {
        setError('');
        try {
            const response = await request(`${urls.entries}/${row.id}/history`);
            setHistory({ row, events: await response.json() });
        } catch (failure) { setError(failure.message); }
    }
    async function download() {
        setExporting(true); setError('');
        try {
            const response = await request(`${urls.export}?${new URLSearchParams(data.filters)}`);
            const blob = await response.blob();
            const href = URL.createObjectURL(blob);
            const link = document.createElement('a'); link.href = href;
            link.download = `工日簿_${data.filters.start || '全部'}_${data.filters.end || '全部'}.xlsx`;
            document.body.appendChild(link); link.click(); link.remove();
            setTimeout(() => URL.revokeObjectURL(href), 1000);
            setNotice('Excel 已生成并开始下载。');
        } catch (failure) { setError(failure.message); }
        finally { setExporting(false); }
    }
    const set = (key) => (event) => setEntry((old) => ({ ...old, [key]: event.target.value }));
    const changeFilter = (key) => (event) => setFilters((old) => ({ ...old, [key]: event.target.value }));

    return <Layout title="工日簿" eyebrow="每日记工 · 工日与加班分开累计">
        <Head title="工日簿" />
        <div className="timebook">
            {notice && <p role="status" className="notice">{notice}</p>}
            {error && <div role="alert" className="form-error">{error}{error.includes('登录已失效') && <a href="/login" target="_blank" rel="noreferrer">打开登录页</a>}</div>}
            {undo && can('delete') && <button disabled={busy} onClick={() => changeDeleted(undo, true)}>撤销删除：{undo.name} · {undo.day}</button>}
            <div className="timebook-totals" aria-label="当前筛选统计">
                {[['记工人数', data.totals.people, '人'], ['累计工日', data.totals.days, '天'], ['加班合计', data.totals.overtime, '小时'], ['记录数', data.totals.count, '条']].map(([label, value, unit]) =>
                    <section className="surface" key={label}><span>{label}</span><strong>{display(value)} <small>{unit}</small></strong></section>)}
            </div>
            {(can('create') || editing && can('update')) && <section className="surface timebook-section">
                <h2>{editing ? '修改记工' : '新增记工'}</h2>
                <form onSubmit={save}><fieldset disabled={busy} className="timebook-form">
                    <label>工作日期<input type="date" required value={entry.day} onChange={set('day')} /></label>
                    <label>姓名<input ref={nameInput} list="timebook-names" required maxLength={40} value={entry.name} onChange={set('name')} autoComplete="off" />
                        <datalist id="timebook-names">{suggestions.map((item) => <option key={item.id} value={item.name}>累计 {display(item.days)} 天</option>)}</datalist></label>
                    <label>工日（天）<select value={entry.days} onChange={set('days')}><option value="1">1 天</option><option value="0.5">0.5 天</option><option value="0">0 天（仅加班）</option></select></label>
                    <label>加班（小时）<input type="number" inputMode="decimal" min="0" max="24" step="any" required value={entry.overtime} onChange={set('overtime')} /></label>
                    <label>工作项目<input maxLength={80} value={entry.project} onChange={set('project')} /></label>
                    <label>备注<input maxLength={500} value={entry.note} onChange={set('note')} /></label>
                    <div className="timebook-actions"><button disabled={busy} type="submit">{busy ? '保存中…' : editing ? '保存修改' : '保存记工'}</button>{editing && <button disabled={busy} type="button" onClick={reset}>取消修改</button>}</div>
                </fieldset></form>
                {suggestionError && <p role="status">{suggestionError}</p>}
                <p className="timebook-hint">新人直接输入姓名即可。同名不同人请添加区分文字；1.5 小时表示 1 小时 30 分钟。</p>
                {editing && <p>修改姓名只改变这一条记工的归属，不会为该人员全部历史更名。</p>}
                {duplicate && can('update') && <button disabled={busy} onClick={() => edit(duplicate)}>打开已有记录修改</button>}
            </section>}
            <section className="surface timebook-section">
                <h2>查询与汇总</h2>
                <form className="timebook-form" onSubmit={(event) => { event.preventDefault(); void load(filters); }}>
                    <label>开始日期<input type="date" value={filters.start} onChange={changeFilter('start')} /></label>
                    <label>结束日期<input type="date" value={filters.end} onChange={changeFilter('end')} /></label>
                    <label>姓名搜索<input maxLength={40} value={filters.q} onChange={changeFilter('q')} /></label>
                    <div className="timebook-actions"><button disabled={loading || busy}>查询</button><button type="button" disabled={loading || busy} onClick={thisMonth}>本月</button><button type="button" disabled={loading || busy} onClick={() => apply({ start: '', end: '', q: '', worker: '' })}>全部历史</button></div>
                </form>
                {filters.worker && <p>精确人员：{data.summary.find((row) => String(row.worker_id) === String(filters.worker))?.name || `编号 ${filters.worker}`} <button disabled={busy} onClick={() => apply({ ...filters, worker: '' })}>清除人员筛选</button></p>}
                {unapplied && <p role="status">筛选条件尚未应用。请先查询；导出仍使用最近一次成功查询条件。</p>}
                {!queryValid && !loading && <p>查询未成功，保留上次结果，导出已禁用。</p>}
                <div className="timebook-actions">
                    <button aria-pressed={view === 'records'} onClick={() => setView('records')}>记工明细</button>
                    <button aria-pressed={view === 'summary'} onClick={() => setView('summary')}>人员汇总</button>
                    {can('export') && <button disabled={loading || busy || exporting || !queryValid} onClick={download}>{exporting ? '导出中…' : '导出 Excel'}</button>}
                </div>
                <p className="timebook-hint">生效范围：{data.filters.start || '不限'} 至 {data.filters.end || '不限'}；姓名：{data.filters.q || '全部'}。工日与加班独立累计。</p>
                <div className="timebook-table" tabIndex={0} role="region" aria-label={view === 'records' ? '记工明细表' : '人员汇总表'} aria-busy={loading}>
                    {view === 'records' ? <table><thead><tr>{['日期', '姓名', '工日（天）', '加班（小时）', '工作项目', '备注', '操作'].map((label) => <th key={label}>{label}</th>)}</tr></thead>
                        <tbody>{data.records.map((row) => <tr key={row.id}>
                            <td data-label="日期">{row.day}</td><td data-label="姓名"><button disabled={busy} onClick={() => worker(row)}>{row.name}</button></td><td data-label="工日（天）">{display(row.days)}</td><td data-label="加班（小时）">{display(row.overtime)}</td><td data-label="工作项目">{row.project || '—'}</td><td data-label="备注">{row.note || '—'}</td>
                            <td className="timebook-action-cell" data-label="操作"><div className="timebook-actions">{can('update') && <button disabled={busy} onClick={() => edit(row)}>修改</button>}{can('audit') && <button disabled={busy} onClick={() => showHistory(row)}>留痕</button>}{can('delete') && <button disabled={busy} onClick={() => setDeleting(row)}>删除</button>}</div></td>
                        </tr>)}{!data.records.length && <tr><td colSpan={7}>当前范围没有记工记录。</td></tr>}</tbody></table>
                        : <table><thead><tr>{['姓名', '累计工日（天）', '加班（小时）', '记录数', '首次日期', '最近日期'].map((label) => <th key={label}>{label}</th>)}</tr></thead>
                            <tbody>{data.summary.map((row) => <tr key={row.worker_id}><td data-label="姓名"><button disabled={busy} onClick={() => { worker(row); setView('records'); }}>{row.name}</button></td><td data-label="累计工日（天）">{display(row.days)}</td><td data-label="加班（小时）">{display(row.overtime)}</td><td data-label="记录数">{row.count}</td><td data-label="首次日期">{row.first_day}</td><td data-label="最近日期">{row.last_day}</td></tr>)}{!data.summary.length && <tr><td colSpan={6}>当前范围没有人员汇总。</td></tr>}</tbody></table>}
                </div>
                {view === 'records' && <div className="timebook-actions"><button disabled={busy || loading || data.pagination.page <= 1} onClick={() => load(data.filters, data.pagination.page - 1)}>上一页</button><span>第 {data.pagination.page} / {data.pagination.last_page} 页 · 共 {data.pagination.total} 条</span><button disabled={busy || loading || data.pagination.page >= data.pagination.last_page} onClick={() => load(data.filters, data.pagination.page + 1)}>下一页</button></div>}
            </section>
            {deleting && <Modal title="确认删除记工" onClose={() => { if (!busy) setDeleting(null); }}><p>{deleting.name} · {deleting.day} · {display(deleting.days)} 天 · 加班 {display(deleting.overtime)} 小时</p><button disabled={busy} onClick={() => changeDeleted(deleting)}>确认删除</button></Modal>}
            {history && <Modal title={`${history.row.name}的操作留痕`} onClose={() => setHistory(null)}>{history.events.map((item) => <article key={item.id}><h3>{item.action} · {item.actor_name || '历史操作人未知'}</h3><p>{item.created_at}</p>{[['操作前', item.before], ['操作后', item.after]].map(([label, row]) => row && <p key={label}>{label}：{row.name} · {row.day} · {display(row.days)} 天 · 加班 {display(row.overtime)} 小时 · 项目 {row.project || '—'} · 备注 {row.note || '—'} · {row.deleted ? '已删除' : '有效'}</p>)}</article>)}</Modal>}
        </div>
    </Layout>;
}
