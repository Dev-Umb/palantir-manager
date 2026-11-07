import { Head, Link, router, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useRef, useState } from 'react';
import Layout from '../../Components/Layout';
import { blankRow, blankOrder, sheetGroups, totals, number, request } from './ledger';
import { columnOrderStorageKey, columnOrderState, readColumnOrder } from '../../Components/objectGridColumnState';
import './finance.css';
const columns = {date:'发货日期',vehicle:'车牌号',name:'货物名称',unit:'单位',quantity:'数量',net_price:'网价（元）',processing_fee:'加工费（元）',price:'单价（元）',amount:'金额（元）',note:'备注'};
const moneyFields = ['price','net_price','processing_fee'];
export default function Show({ project, ledger, imports: initialImports, urls }) {
    const [orders, setOrders] = useState(ledger.orders), [status, setStatus] = useState('已加载'), [error, setError] = useState(null), [menu, setMenu] = useState(null), [imports, setImports] = useState(initialImports), [preview, setPreview] = useState(null), [busy, setBusy] = useState(false);
    const current = useRef(orders), saved = useRef(JSON.stringify(orders)), revision = useRef(ledger.revision), inflight = useRef(false), timer = useRef(), stopped = useRef(false), alive = useRef(true), wrap = useRef(), uploadInput = useRef();
    const [selection, setSelection] = useState(null), [unitPicker, setUnitPicker] = useState(null);
    const drag = useRef(null), suppressClick = useRef(false);
    const storageKey = columnOrderStorageKey(usePage().props.auth?.user?.id ?? 'anonymous', 'finance-shipments');
    const [columnOrder, setColumnOrder] = useState(() => readColumnOrder(storageKey));
    const columnKeys = columnOrderState(columnOrder, Object.keys(columns)).map(item => item.colId);
    const draggedColumn = useRef(null);
    useEffect(() => { setColumnOrder(readColumnOrder(storageKey)); }, [storageKey]);
    function moveColumn(from, to) {
        if (!from || from === to) return;
        const next = [...columnKeys]; next.splice(next.indexOf(from), 1); next.splice(columnKeys.indexOf(to), 0, from);
        setColumnOrder(next);
        try { window.localStorage.setItem(storageKey, JSON.stringify(next)); } catch { /* Keep the current layout when browser storage is unavailable. */ }
    }
    const summary = totals(orders);
    const groups = sheetGroups(orders);
    const flatRows = groups.flatMap(group => group.rows);
    const importPending = imports.reduce((sum, item) => sum + (item.pending_outside_ledger ?? item.counts.pending), 0);
    const dirty = () => saved.current !== JSON.stringify(current.current);
    async function flush() {
        clearTimeout(timer.current);
        if (inflight.current || !dirty() || stopped.current) return;
        const snapshot = JSON.stringify(current.current); inflight.current = true; setStatus('保存中…');
        try {
            const response = await request(urls.save, { revision: revision.current, orders: JSON.parse(snapshot) }, 'PUT');
            revision.current = response.revision; saved.current = snapshot;
            if (alive.current) { setError(null); setStatus(dirty() ? '等待保存…' : '已自动保存'); }
        } catch (e) {
            stopped.current = true;
            if (alive.current) { setError(e); setStatus('保存失败 · 输入已保留'); }
        } finally {
            inflight.current = false;
            if (alive.current && dirty() && !stopped.current) timer.current = setTimeout(flush, 100);
        }
    }
    function change(next) { current.current = next; setOrders(next); setStatus(stopped.current ? '尚未保存 · 请处理错误' : '等待保存…'); clearTimeout(timer.current); if (!stopped.current) timer.current = setTimeout(flush, 600); }
    useEffect(() => {
        alive.current = true;
        const beforeUnload = e => { if (dirty() || inflight.current) { e.preventDefault(); e.returnValue = ''; } };
        const off = router.on('before', e => { if (dirty() || inflight.current) { e.preventDefault(); setStatus('请等待保存成功后离开；输入仍在当前页'); flush(); } });
        window.addEventListener('beforeunload', beforeUnload);
        return () => { alive.current = false; clearTimeout(timer.current); off(); window.removeEventListener('beforeunload', beforeUnload); };
    }, []);
    useEffect(() => { if (preview) wrap.current?.querySelector('.finance-preview')?.scrollIntoView?.({ block: 'start' }); }, [preview]);
    useEffect(() => { const close = e => { if (e.key === 'Escape' || (e.type === 'pointerdown' && !e.target.closest('.finance-context'))) setMenu(null); }; document.addEventListener('pointerdown', close); document.addEventListener('keydown', close); return () => { document.removeEventListener('pointerdown', close); document.removeEventListener('keydown', close); }; }, []);
    useEffect(() => {
        const end = () => { drag.current = null; };
        document.addEventListener('mouseup', end);
        return () => document.removeEventListener('mouseup', end);
    }, []);
    function selectTo(id, anchor = selection?.start) {
        const group = groups.find(group => group.rows.some(item => item.row.id === anchor));
        if (!group?.rows.some(item => item.row.id === id)) return;
        setSelection({ start: anchor, end: id });
    }
    const selectedGroup = groups.find(group => group.rows.some(item => item.row.id === selection?.start));
    const selectedIndices = selectedGroup && [selection.start, selection.end].map(id => selectedGroup.rows.findIndex(item => item.row.id === id)).sort((a,b) => a-b);
    const selectedRows = selectedIndices ? selectedGroup.rows.slice(selectedIndices[0], selectedIndices[1] + 1) : [];
    function subtotal(removeStart = null, target = selectedRows.at(-1)) {
        if (!target) return;
        const next = structuredClone(current.current), row = next[target.oi].rows[target.ri];
        row.subtotal_starts = removeStart ? (row.subtotal_starts || []).filter(id => id !== removeStart) : [...new Set([...(row.subtotal_starts || []), selectedRows[0].row.id])];
        change(next); setMenu(null);
    }
    function edit(orderIndex, rowIndex, key, value) {
        const next = structuredClone(current.current);
        if (!next[orderIndex]) next.push({ ...blankOrder(), date: null });
        const rows = next[orderIndex].rows;
        if (!rows[rowIndex]) rows.push(blankRow());
        rows[rowIndex][key] = value === '' && ['quantity', ...moneyFields].includes(key) ? null : value;
        change(next);
    }
    function insert(orderIndex, rowIndex) {
        const next = structuredClone(current.current);
        if (!next[orderIndex]) next.push({ ...blankOrder(), date: null, rows: [blankRow()] });
        next[orderIndex].rows.splice(rowIndex + 1, 0, blankRow()); change(next); setMenu(null);
        setTimeout(() => wrap.current?.querySelector(`[data-cell="${orderIndex}-${rowIndex + 1}-name"]`)?.focus(), 0);
    }
    function context(e, oi, ri) { e.preventDefault(); const id = current.current[oi]?.rows[ri]?.id; if (!selectedRows.some(item => item.row.id === id)) setSelection(id ? {start:id,end:id} : null); const rect = wrap.current.getBoundingClientRect(); setMenu({ oi, ri, x: Math.max(0, Math.min((e.clientX || e.currentTarget.getBoundingClientRect().left) - rect.left, rect.width - 210)), y: (e.clientY || e.currentTarget.getBoundingClientRect().bottom) - rect.top }); }
    function keyboard(e, oi, ri, key) {
        if (e.key === 'Enter' && !e.nativeEvent.isComposing) {
            e.preventDefault();
            const index = flatRows.findIndex(row => row.oi === oi && row.ri === ri);
            const next = flatRows[index + 1] || { oi: current.current.length, ri: 0 };
            wrap.current.querySelector(`[data-cell="${next.oi}-${next.ri}-${key}"]`)?.focus(); flush();
        }
        if (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) context(e, oi, ri);
    }
    async function upload(file) { if (!file) return; setBusy(true); try { const form = new FormData(); form.append('file', file); setPreview(await request(urls.preview, form)); } catch (e) { setError(e); } finally { setBusy(false); } }
    async function commit() { setBusy(true); try { const result = await request(urls.commit, { id: preview.id }); current.current = result.orders; saved.current = JSON.stringify(result.orders); revision.current = result.revision; setOrders(result.orders); setImports(list => [result.import, ...list.filter(x => x.id !== preview.id)]); setPreview(null); setError(null); setStatus('导入完成'); } catch (e) { setError(e); } finally { setBusy(false); } }
    function renderRow({oi, ri, row, source}, index, blank = false) {
        const labels = {date:'发货日期',vehicle:'车牌号',name:'货物名称',unit:'单位',quantity:'数量',price:'单价',net_price:'网价',processing_fee:'加工费',note:'备注'};
        const cell = key => key === 'amount' ? <td key={key} className="finance-calculated">{row.quantity != null && row.price != null ? number(Math.round(Math.round(Number(row.quantity) * 1000) * Math.round(Number(row.price) * 100) / 1000) / 100) : '—'}</td> : <td key={key}>{key === 'unit'
            ? <select size={unitPicker === row.id ? 4 : undefined} aria-label={`第${index + 1}行单位`} data-cell={`${oi}-${ri}-${key}`} value={row.unit || '吨'} onChange={e => { edit(oi, ri, key, e.target.value); setUnitPicker(null); }} onBlur={() => { setUnitPicker(null); flush(); }} onKeyDown={e => { if (e.key === 'Escape') setUnitPicker(null); keyboard(e, oi, ri, key); }}>{['吨','件','台','米'].map(unit => <option key={unit}>{unit}</option>)}</select>
            : <input aria-label={`第${index + 1}行${labels[key]}`} data-cell={`${oi}-${ri}-${key}`} type={key === 'date' ? 'date' : ['quantity',...moneyFields].includes(key) ? 'number' : 'text'} min={['quantity',...moneyFields].includes(key) ? '0' : undefined} max={moneyFields.includes(key) ? '99999.99' : key === 'quantity' ? '99999.999' : undefined} step={moneyFields.includes(key) ? '0.01' : '0.001'} value={row[key] ?? ''} placeholder={blank && key === 'name' ? '直接输入新明细' : ''} onChange={e => edit(oi, ri, key, e.target.value)} onBlur={flush} onKeyDown={e => keyboard(e, oi, ri, key)} />}</td>;
        return <tr key={row.id} title={source || undefined} aria-selected={!blank && selectedRows.some(item => item.row.id === row.id)} className={!blank && selectedRows.some(item => item.row.id === row.id) ? 'finance-selected-row' : undefined}
            onMouseDownCapture={e => { if (e.button !== 0 || blank || e.target.closest('button')) return; if (e.shiftKey && selection) { e.preventDefault(); selectTo(row.id); } else { drag.current = { id: row.id }; suppressClick.current = false; if (e.target.tagName === 'SELECT' && unitPicker !== row.id) e.preventDefault(); setSelection(null); } }}
            onMouseEnter={e => { if (!blank && drag.current && e.buttons === 1 && (drag.current.id !== row.id || drag.current.moved)) { e.preventDefault(); drag.current.moved = true; suppressClick.current = true; selectTo(row.id, drag.current.id); window.getSelection()?.removeAllRanges(); } }}
            onMouseUpCapture={e => { if (!suppressClick.current && e.target.tagName === 'SELECT' && unitPicker !== row.id) { e.target.focus(); setUnitPicker(row.id); } }}
            onClickCapture={e => { if (suppressClick.current) { e.preventDefault(); e.stopPropagation(); suppressClick.current = false; } }}
            onDragStart={e => e.preventDefault()}
            onContextMenu={e => { if (!selectedRows.some(item => item.row.id === row.id)) setSelection(blank ? null : {start:row.id,end:row.id}); context(e, oi, ri); }}>

            <th scope="row" tabIndex={blank ? undefined : 0} aria-label={`选择第${index + 1}行`} onKeyDown={e => { if (e.key === ' ') { e.preventDefault(); setSelection({start:row.id,end:row.id}); } if (e.shiftKey && ['ArrowUp','ArrowDown'].includes(e.key)) { e.preventDefault(); const next = flatRows[index + (e.key === 'ArrowDown' ? 1 : -1)]; if (next) selectTo(next.row.id, selection?.start || row.id); } if (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) context(e,oi,ri); }}>{index + 1}</th>{columnKeys.map(cell)}
            <td><button className="ghost-button" aria-label={`第${index + 1}行操作`} onClick={e => context(e, oi, ri)}>⋯</button></td>
        </tr>;
    }
    function summaryCells(sum, label, action = null) {
        return <><th scope="row">{label === '合计' ? '合计' : '小计'}</th>{columnKeys.map(key => <td key={key}>{key === 'name' && label !== '小计' && label !== '合计' ? label : key === 'quantity' ? `${number(sum.tonnes)} 吨` : key === 'amount' ? number(sum.amount) : key === 'note' && sum.pending > 0 ? `${sum.pending} 行待定` : ''}</td>)}<td>{action}</td></>;
    }
    let rowNumber = 0;
    return <Layout hideHeader hidePasswordReminder><Head title={`${project.title || project.code} · 发货明细`} /><div className="finance-workbench finance-sheet" ref={wrap}>
        <div className="finance-sheet-toolbar"><div className="finance-actions"><Link href={`${urls.index}?owner=${project.owner_id}`}>← 返回项目</Link><h2>{project.title || project.code}</h2></div><div className="finance-actions"><span role="status" className="finance-status">{status}</span><button type="button" disabled={busy || dirty() || inflight.current} onClick={() => uploadInput.current?.click()}>{busy ? '处理中…' : '导入Excel/CSV'}</button><input ref={uploadInput} hidden aria-label="导入发货数据" type="file" accept=".xls,.xlsx,.csv" disabled={busy || dirty() || inflight.current} onChange={e => { upload(e.target.files?.[0]); e.target.value = ''; }} /></div></div>
        {error && <div role="alert" className="finance-warning">{error.message} {stopped.current && error.status !== 409 && <button onClick={() => { stopped.current = false; setError(null); flush(); }}>重试保存</button>}{error.status === 409 && <span>请保留本页，在另一个页面核对最新记录；此页不会自动覆盖。</span>}</div>}
        <div className="finance-scroll"><table className="finance-grid finance-sheet-grid"><colgroup><col style={{width:60}} />{columnKeys.map(key => <col key={key} style={{width: key === 'date' ? 145 : ['name','note'].includes(key) ? 180 : key === 'unit' ? 70 : 120}} />)}<col style={{width:60}} /></colgroup><thead><tr><th>序号</th>{columnKeys.map(key => <th key={key} draggable tabIndex={0} title="拖动调整列顺序，或按 Alt + 左右方向键" onDragStart={e => { draggedColumn.current = key; e.dataTransfer.setData('text/plain', key); }} onDragOver={e => e.preventDefault()} onDrop={e => { e.preventDefault(); moveColumn(draggedColumn.current,key); draggedColumn.current = null; }} onDragEnd={() => { draggedColumn.current = null; }} onKeyDown={e => { if (e.altKey && ['ArrowLeft','ArrowRight'].includes(e.key)) { e.preventDefault(); const target = columnKeys[columnKeys.indexOf(key) + (e.key === 'ArrowLeft' ? -1 : 1)]; if (target) moveColumn(key,target); } }}>{columns[key]}</th>)}<th></th></tr></thead>
            {groups.map(group => { const sum=totals(group.orders); return <tbody key={group.key}>
                <tr className="finance-sheet-source"><th colSpan={columnKeys.length + 2}>{group.key}</th></tr>
                {group.rows.map((row, position) => {
                    const index = rowNumber++;
                    return <Fragment key={row.row.id}>{renderRow(row,index)}{(row.row.subtotal_starts || []).map(start => {
                        const first = group.rows.findIndex(item => item.row.id === start);
                        if (first < 0 || first > position) return null;
                        const sum = totals([{rows:group.rows.slice(first,position+1).map(item => item.row)}]);
                        return <tr key={start} className="finance-sheet-subtotal">{summaryCells(sum,`第 ${index - position + first + 1}–${index + 1} 行小计`,<button className="ghost-button" onClick={() => subtotal(start,row)}>删除小计</button>)}</tr>;
                    })}</Fragment>;
                })}
                <tr className="finance-sheet-subtotal">{summaryCells(sum,'小计')}</tr>
            </tbody>; })}
            <tbody>{renderRow({oi:orders.length,ri:0,row:{id:'trailing',date:null,vehicle:'',name:'',unit:'吨',quantity:null,price:null,note:''}},rowNumber,true)}</tbody>
            <tfoot><tr>{summaryCells(summary,'合计')}</tr></tfoot>
        </table></div>
        {menu && <div className="finance-context" role="group" aria-label="明细行操作" style={{ left: menu.x, top: menu.y }}><span>{selectedRows.length ? `已选中 ${selectedRows.length} 行` : '明细操作'}</span><button autoFocus onClick={() => insert(menu.oi, menu.ri)}>在下方插入明细</button><button disabled={!selectedRows.length} onClick={() => subtotal()}>在选区下方插入小计</button><button className="ghost-button" onClick={() => setMenu(null)}>取消</button></div>}
        {preview && <section className="surface finance-preview"><div className="section-head"><h3>导入预检 · {preview.name}</h3><button className="ghost-button" onClick={() => setPreview(null)}>关闭</button></div><p>可进入明细 {preview.importable_count ?? preview.counts.ready} 行（含待确认 {preview.draft_count ?? 0} 行） · 待订正 {preview.counts.pending} 行 · 排除 {preview.counts.excluded} 行</p><p className="muted">未标单位按吨统计；件、台、米不计吨数。原文件和排除记录保留。</p><button disabled={busy || dirty() || !!preview.imported_at || !(preview.importable_count ?? preview.counts.ready)} onClick={commit}>{preview.imported_at ? '已导入，不会重复添加' : '确认导入发货明细'}</button><ImportRows records={preview.records} /></section>}
        <details className="finance-import-history"><summary>导入记录{importPending > 0 ? ` · ${importPending} 行待处理` : ''}</summary>{imports.length ? imports.map(item => <details key={item.id}><summary>{item.name} · {item.imported_at ? '已处理' : '待确认'} · 可导入 {item.counts.ready} / 待订正 {item.counts.pending} / 排除 {item.counts.excluded}</summary>{!item.imported_at && <button onClick={() => setPreview(item)}>查看预检</button>}<ImportRows records={item.records} /></details>) : <p className="muted">尚无导入记录</p>}</details>
    </div></Layout>;
}
function ImportRows({ records }) { return <div className="finance-scroll"><table><thead><tr><th>来源</th><th>日期 / 货物</th><th>数量</th><th>网价（元）</th><th>加工费（元）</th><th>处理结果</th></tr></thead><tbody>{records.map((r, i) => <tr key={i}><td>{r.source}</td><td>{r.date || '—'}<small>{r.name}</small></td><td>{number(r.quantity)} {r.unit || '吨'}</td><td>{number(r.net_price)}</td><td>{number(r.processing_fee)}</td><td>{r.ledger_order_id ? '已进入明细' : ({ready:'可导入',pending:'待订正',excluded:'排除'})[r.status]}<small>{r.reason}</small>{r.raw?.length > 0 && <details><summary>原始内容</summary><p>{r.raw.map(value => value ?? '').join(' | ')}</p></details>}</td></tr>)}</tbody></table></div>; }
