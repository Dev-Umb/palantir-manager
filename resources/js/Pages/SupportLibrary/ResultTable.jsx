const valid = (value) => typeof value === 'number' && Number.isFinite(value);
export const weight = (value) => valid(value) ? value.toFixed(1) : '待补';
const remark = (value) => !value || ['未注明中孔', '—'].includes(value) ? '/' : value;
export function subtotal(items, key, mass = false) {
    const values = items.map((item) => item[key]);
    const known = values.filter(valid);
    if (!known.length) return '待补';
    const sum = Number(known.reduce((a, b) => a + b, 0).toFixed(6));
    return (known.length < values.length ? '已知 ' : '') + (mass ? weight(sum) : sum);
}

export default function ResultTable({ result, canExport, exporting, onExport, feedback }) {
    const complete = result?.bracing_complete;
    const mass = complete ? result?.estimated_with_bolts_weight_kg : result?.accounted_with_bolts_weight_kg;
    const conditions = result ? (result.query_entries || [result.query_conditions]) : [];
    const groups = result ? [
        ['钢柱小计', result.merged.filter((m) => m.kind === '钢柱')],
        [complete ? '柱撑小计' : '柱撑已计小计', result.merged.filter((m) => m.kind === '柱撑')],
        [complete ? '合计' : '已计小计', result.merged],
    ] : [];
    return <section className="support-card" aria-label="支架组合检索结果">
        <div className="support-section-head"><div><h3>支架组合检索结果</h3><p>{result ? conditions.map((c, i) => `搭配${i + 1}：${c.support_use}${c.column_brace_model ? ' / ' + c.column_brace_model : ''} / φ${c.diameter_mm} · ${c.combination}`).join('；') : '等待检索'}</p></div>
            {canExport && <button type="button" disabled={!result || exporting} onClick={onExport}>{exporting ? '正在导出…' : '导出明细 Excel（.xlsx）'}</button>}</div>
        {feedback && <p role="status" aria-live="polite">{feedback}</p>}
        {!result ? <p>提交条件后显示结果。</p> : <>
            <div className="support-metrics">
                <div><span>支架组数</span><strong>{result.total_groups} 组</strong><p>{result.total_column_positions}个柱位 · 钢柱段 {result.total_columns} 根 · {result.total_faces}个分段柱撑面</p><p>钢柱重量：{weight(result.column_weight_kg)} kg</p></div>
                <div><span>{complete ? '柱撑合计' : '已匹配柱撑（未算齐）'}</span><strong>{result.total_braces} 根</strong><p>{complete ? `${result.merged.filter((m) => m.kind === '柱撑').length}类柱撑，孔位分别统计` : `缺少${result.missing_matches.length}项搭配、${result.total_faces - result.matched_faces}个分段柱撑面`}</p></div>
                <div><span>{complete ? '含螺栓估算重' : '已计含螺栓估算重'}</span><strong>{mass === null ? '待补重量' : `${weight(mass)} kg`}</strong><p>构件{complete ? '' : '已匹配'}小计 {weight(result.known_components_estimated_weight_kg)} kg；连接板等未计入</p></div>
            </div>
            {!complete && <div className="support-warning" role="status">{result.missing_matches.map((m, i) => <p key={i}>{m.message}；该跨柱撑及其配套螺栓未计入。</p>)}</div>}
            <p>M22×80 螺栓{complete ? '合计' : '已计'}：{(complete ? result.bolts.quantity : result.bolts.known_quantity) ?? '待确认'} 套（柱撑{(complete ? result.bolts.brace_bolt_quantity : result.bolts.known_brace_bolt_quantity) ?? '待确认'}＋钢柱接头{result.bolts.column_bolt_quantity ?? '待确认'}）；单重 0.44 kg/套；重量 {weight(complete ? result.bolts.weight_kg : result.bolts.known_weight_kg)} kg</p>
            <h4>合并清单</h4><div className="support-table-wrap"><table><thead><tr>{['追踪编号', '类别 / 规格', '长度mm', '孔位 / 备注', '数量（根）', '单重 / 总重kg', '螺栓数量（套）', '螺栓重量kg'].map((h) => <th key={h}>{h}</th>)}</tr></thead>
                <tbody>{result.merged.map((m) => <tr key={m.part_id}><td>{m.part_id}</td><td>{m.kind} / {m.spec}</td><td>{m.length_mm}</td><td>{remark(m.hole)}</td><td>{m.quantity}</td><td>{weight(m.accounted_unit_weight_kg)} / {weight(m.accounted_weight_kg)}</td><td>{m.bolt_quantity ?? '待确认'}</td><td>{weight(m.bolt_weight_kg)}</td></tr>)}</tbody>
                <tfoot>{groups.filter(([, items]) => items.length).map(([label, items]) => <tr key={label}><th>{label}</th><td>/</td><td>/</td><td>/</td><td>{subtotal(items, 'quantity')}</td><td>{subtotal(items, 'accounted_weight_kg', true)}</td><td>{subtotal(items, 'bolt_quantity')}</td><td>{subtotal(items, 'bolt_weight_kg', true)}</td></tr>)}</tfoot>
            </table></div><p className="support-grand-total">{complete ? '合计' : '已计合计'}重量（含螺栓）：{mass === null ? '待补重量' : `${weight(mass)} kg`}</p>
            <p className="muted">P开头为原表行号追踪编号，并非图纸编号。同长度带中孔构件独立列项。钢柱接头螺栓归入下方钢柱段，每个接头只计一次；柱底、柱顶不计。</p>
        </>}
    </section>;
}
