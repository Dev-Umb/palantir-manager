import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Layout from '../../Components/Layout';
import { blankRow, blankOrder, totals, number, request } from './ledger';
import './finance.css';
export default function Show({ project, ledger, imports: initialImports, urls }) {
    const [orders, setOrders] = useState(ledger.orders), [status, setStatus] = useState('已加载'), [error, setError] = useState(null), [menu, setMenu] = useState(null), [imports, setImports] = useState(initialImports), [preview, setPreview] = useState(null), [busy, setBusy] = useState(false);
    const current = useRef(orders), saved = useRef(JSON.stringify(orders)), revision = useRef(ledger.revision), inflight = useRef(false), timer = useRef(), stopped = useRef(false), alive = useRef(true), wrap = useRef();
    const summary = totals(orders);
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
    function edit(orderIndex, rowIndex, key, value) {
        const next = structuredClone(current.current); const rows = next[orderIndex].rows;
        if (!rows[rowIndex]) rows.push(blankRow());
        rows[rowIndex][key] = value === '' && ['quantity', 'price'].includes(key) ? null : value;
        change(next);
    }
    function header(index, key, value) { const next = structuredClone(current.current); next[index][key] = value || null; change(next); }
    function insert(orderIndex, rowIndex) { const next = structuredClone(current.current); next[orderIndex].rows.splice(rowIndex + 1, 0, blankRow()); change(next); setMenu(null); setTimeout(() => wrap.current?.querySelector(`[data-cell="${orderIndex}-${rowIndex + 1}-name"]`)?.focus(), 0); }
    function context(e, oi, ri) { e.preventDefault(); const rect = wrap.current.getBoundingClientRect(); setMenu({ oi, ri, x: Math.max(0, Math.min((e.clientX || e.currentTarget.getBoundingClientRect().left) - rect.left, rect.width - 210)), y: (e.clientY || e.currentTarget.getBoundingClientRect().bottom) - rect.top }); }
    function keyboard(e, oi, ri, key) { if (e.key === 'Enter' && !e.nativeEvent.isComposing) { e.preventDefault(); wrap.current.querySelector(`[data-cell="${oi}-${ri + 1}-${key}"]`)?.focus(); flush(); } if (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) context(e, oi, ri); }
    async function upload(file) { if (!file) return; setBusy(true); try { const form = new FormData(); form.append('file', file); setPreview(await request(urls.preview, form)); } catch (e) { setError(e); } finally { setBusy(false); } }
    async function commit() { setBusy(true); try { const result = await request(urls.commit, { id: preview.id }); current.current = result.orders; saved.current = JSON.stringify(result.orders); revision.current = result.revision; setOrders(result.orders); setImports(list => [result.import, ...list.filter(x => x.id !== preview.id)]); setPreview(null); setError(null); setStatus('导入完成'); } catch (e) { setError(e); } finally { setBusy(false); } }
    return <Layout title="财务工作台" eyebrow="发货管理"><Head title={`${project.title} · 发货明细`} /><div className="finance-workbench" ref={wrap}>
        <nav className="finance-crumb"><Link href={urls.index}>全部业务员</Link><span>/</span><Link href={`${urls.index}?owner=${project.owner_id}`}>{project.owner}</Link><span>/ {project.title}</span></nav>
        <div className="section-head"><div><h2>{project.title}</h2><p className="muted">{project.owner} · {project.code}</p></div><div className="finance-actions"><label className="finance-upload">{busy ? '处理中…' : '导入 Excel / CSV'}<input aria-label="导入发货数据" type="file" accept=".xls,.xlsx,.csv" disabled={busy || dirty() || inflight.current} onChange={e => { upload(e.target.files?.[0]); e.target.value = ''; }} /></label><button disabled={busy} onClick={() => change([blankOrder(), ...current.current])}>新建发货单</button></div></div>
        {project.local_source && <p className="finance-warning">本地导入项目 · 尚未匹配正式项目：{project.local_source}</p>}
        <section className="surface finance-summary"><div><small>累计发货（吨）</small><strong>{number(summary.tonnes)}</strong></div><div><small>最后发货日期</small><strong>{summary.last_date || '—'}</strong></div><div><small>发货单</small><strong>{orders.length}</strong></div><div><small>待补全 / 非吨待定</small><strong>{summary.pending + importPending}</strong></div></section>
        <p className="muted">{importPending > 0 && `另有 ${importPending} 行导入记录待订正，当前吨数不代表完整历史总量。`}发货台账独立保存，不修改业务资料。件、台、米先待定；未完整行不计入吨数。</p>
        <div className="section-head"><div><h3>发货明细记录</h3><p className="muted">直接编辑单元格 · 末尾空白行连续录入 · 右键在下方插行 · Enter 向下 / Tab 横向</p></div><span role="status" className="finance-status">{status}</span></div>
        {error && <div role="alert" className="finance-warning">{error.message} {stopped.current && error.status !== 409 && <button onClick={() => { stopped.current = false; setError(null); flush(); }}>重试保存</button>}{error.status === 409 && <span>请保留本页，在另一个页面核对最新记录；此页不会自动覆盖。</span>}</div>}
        {orders.map((order, oi) => { const sum = totals([order]); const rows = [...order.rows, { id: `blank-${order.id}`, name: '', unit: '吨', quantity: null, price: null, note: '' }]; return <section className="surface finance-order" key={order.id}><div className="finance-order-head"><label>发货日期<input aria-label={`第${oi + 1}单发货日期`} type="date" value={order.date || ''} onChange={e => header(oi, 'date', e.target.value)} onBlur={flush} /></label><label>车号 / 承运方式<input aria-label={`第${oi + 1}单车号`} value={order.vehicle || ''} onChange={e => header(oi, 'vehicle', e.target.value)} onBlur={flush} /></label><span className="muted">单据 {oi + 1}</span></div>{order.source && <p className="finance-source">来源：{order.source}</p>}<div className="finance-scroll"><table className="finance-grid"><thead><tr><th>#</th><th>货物名称</th><th>单位</th><th>数量</th><th>单价（元）</th><th>金额（元）</th><th>备注</th><th /></tr></thead><tbody>{rows.map((row, ri) => <tr key={ri} onContextMenu={e => context(e, oi, ri)}><th scope="row">{ri + 1}</th>{['name', 'unit', 'quantity', 'price'].map(key => <td key={key}>{key === 'unit' ? <select aria-label={`第${oi + 1}单第${ri + 1}行单位`} data-cell={`${oi}-${ri}-${key}`} value={row.unit || ''} onChange={e => edit(oi, ri, key, e.target.value)} onBlur={flush} onKeyDown={e => keyboard(e, oi, ri, key)}><option value="">待定</option>{['吨', '件', '台', '米'].map(unit => <option key={unit}>{unit}</option>)}</select> : <input aria-label={`第${oi + 1}单第${ri + 1}行${{name:'货物名称',quantity:'数量',price:'单价'}[key]}`} data-cell={`${oi}-${ri}-${key}`} type={key === 'name' ? 'text' : 'number'} min="0" max={key === 'price' ? '99999.99' : '99999.999'} step={key === 'price' ? '0.01' : '0.001'} value={row[key] ?? ''} placeholder={key === 'name' && ri === order.rows.length ? '直接输入新明细' : ''} onChange={e => edit(oi, ri, key, e.target.value)} onBlur={flush} onKeyDown={e => keyboard(e, oi, ri, key)} />}</td>)}<td className="finance-calculated">{row.quantity != null && row.price != null ? number(Math.round(Math.round(Number(row.quantity) * 1000) * Math.round(Number(row.price) * 100) / 1000) / 100) : '—'}</td><td><input aria-label={`第${oi + 1}单第${ri + 1}行备注`} value={row.note || ''} onChange={e => edit(oi, ri, 'note', e.target.value)} onBlur={flush} /></td><td><button className="ghost-button" aria-label={`第${oi + 1}单第${ri + 1}行操作`} onClick={e => context(e, oi, ri)}>⋯</button></td></tr>)}</tbody><tfoot><tr><th colSpan="3">本单自动小计</th><td>{number(sum.tonnes)} 吨</td><td>已知金额</td><td>{number(sum.amount)}</td><td colSpan="2">{sum.pending ? `${sum.pending} 行待定` : '按吨统计'}</td></tr></tfoot></table></div></section>; })}
        {!orders.length && <section className="surface">暂无发货单，可新建或导入历史数据。</section>}
        {menu && <div className="finance-context" role="group" aria-label="明细行操作" style={{ left: menu.x, top: menu.y }}><span>第 {menu.ri + 1} 行</span><button autoFocus onClick={() => insert(menu.oi, menu.ri)}>在下方插入明细</button><button className="ghost-button" onClick={() => setMenu(null)}>取消</button></div>}
        {preview && <section className="surface finance-preview"><div className="section-head"><h3>导入预检 · {preview.name}</h3><button className="ghost-button" onClick={() => setPreview(null)}>关闭</button></div><p>可进入明细 {preview.importable_count ?? preview.counts.ready} 行（含待确认 {preview.draft_count ?? 0} 行） · 待订正 {preview.counts.pending} 行 · 排除 {preview.counts.excluded} 行</p><p className="muted">实际发货明细均可导入；缺单位或日期的标为待确认，暂不计吨数。原文件和排除记录保留。</p><button disabled={busy || dirty() || !!preview.imported_at || !(preview.importable_count ?? preview.counts.ready)} onClick={commit}>{preview.imported_at ? '已导入，不会重复添加' : '确认导入发货明细'}</button><ImportRows records={preview.records} /></section>}
        <section className="surface"><h3>导入记录与待订正项</h3>{imports.length ? imports.map(item => <details key={item.id}><summary>{item.name} · {item.imported_at ? '已处理' : '待确认'} · 可导入 {item.counts.ready} / 待订正 {item.counts.pending} / 排除 {item.counts.excluded}</summary>{!item.imported_at && <button onClick={() => setPreview(item)}>查看预检</button>}<ImportRows records={item.records} /></details>) : <p className="muted">尚无导入记录</p>}</section>
    </div></Layout>;
}
function ImportRows({ records }) { return <div className="finance-scroll"><table><thead><tr><th>来源</th><th>日期 / 货物</th><th>数量</th><th>处理结果</th></tr></thead><tbody>{records.map((r, i) => <tr key={i}><td>{r.source}</td><td>{r.date || '—'}<small>{r.name}</small></td><td>{number(r.quantity)} {r.unit || '单位待定'}</td><td>{r.ledger_order_id ? '已进入明细' : ({ready:'可导入',pending:'待订正',excluded:'排除'})[r.status]}<small>{r.reason}</small></td></tr>)}</tbody></table></div>; }
