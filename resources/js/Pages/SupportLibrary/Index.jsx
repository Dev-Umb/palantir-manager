import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Layout from '../../Components/Layout';
import { parseGroupCombination } from './parser';
import { request, requestId, saveDownload } from './api';
import LibraryEditor from './LibraryEditor';
import ResultTable from './ResultTable';
import './support-library.css';

const clone = (value) => JSON.parse(JSON.stringify(value));
const emptyLibrary = { parts: [], frames: [], composition: [] };
const presets = [['6/4', '6米单柱 / 4组'], ['6+5/4', '6+5米叠接单柱 / 4组'], ['6+5@2+3@3+3/4', '11米高 / 横向2+3米 / 纵向3+3米 / 4组'], ['6+5@2@3/4', '6+5米 / 单跨 / 4组'], ['6@2@3/4', '6米 / 横向2米 / 纵向3米 / 4组'], ['2@2@3/1', '2米 / 横向2米 / 纵向3米 / 1组']];
export function preview(expression) {
    try {
        const groups = parseGroupCombination(expression);
        return { groups, single: groups.every((g) => g.column_only), text: groups.map((g, i) => `第${i + 1}项：高度${g.height_segments_mm.map((h) => h / 1000).join('+')}米（总高${g.height_mm / 1000}米） · ${g.column_only ? `单柱，${g.groups}组，${g.height_segments_mm.length * g.groups}根钢柱段` : `横向${g.transverse_spans_mm.map((h) => h / 1000).join('+')}米 / 纵向${g.longitudinal_spans_mm.map((h) => h / 1000).join('+')}米 · ${g.groups}组 · ${(g.transverse_spans_mm.length + 1) * (g.longitudinal_spans_mm.length + 1)}柱位/组`}`).join('；') };
    } catch (error) { return { groups: null, single: false, text: error.message }; }
}
function Combination({ entry, index, update, remove, inputRef }) {
    const parsed = preview(entry.expression);
    return <section className="support-combination" aria-label={`搭配${index + 1}`}><div className="support-section-head"><h4>搭配{index + 1}</h4>{index > 0 && <button type="button" onClick={remove}>删除此搭配</button>}</div>
        <div className="support-fields">
            <label>支架用途<select required value={entry.criteria.support_use} onChange={(e) => update({ criteria: { ...entry.criteria, support_use: e.target.value } })}><option value="">请选择用途</option><option>吊装</option><option>顶推</option></select></label>
            <label>{parsed.single ? '柱撑型号（单柱无需选择）' : '柱撑型号'}<select disabled={parsed.single} required={!parsed.single} value={entry.criteria.column_brace_model || ''} onChange={(e) => update({ criteria: { ...entry.criteria, column_brace_model: e.target.value } })}><option value="">{parsed.single ? '单柱无需选择' : '请选择型号'}</option><option>C14A</option><option>C16A</option></select></label>
            <label>钢柱规格<select required value={entry.criteria.diameter_mm || ''} onChange={(e) => update({ criteria: { ...entry.criteria, diameter_mm: Number(e.target.value) || '' } })}><option value="">请选择钢柱直径</option><option value="273">φ273</option><option value="530">φ530</option></select></label>
        </div><label>支架组合或单柱（/后为组数，可省略）<input ref={inputRef} value={entry.expression} maxLength={20000} autoComplete="off" spellCheck={false} placeholder="例如 6+5@2+3@3+3/4 或 6/4" onChange={(e) => update({ expression: e.target.value })} /></label>
        <p className={parsed.groups ? 'support-preview' : 'support-warning'} aria-live="polite">{parsed.text}</p>
    </section>;
}

export default function Index({ initial, capabilities, urls }) {
    const [data, setData] = useState(initial);
    const library = data?.library || emptyLibrary;
    const [entries, setEntries] = useState([{ expression: '6+5@2+3@3+3/4', criteria: { support_use: '', column_brace_model: '', diameter_mm: '' } }]);
    const entriesRef = useRef(entries); entriesRef.current = entries;
    const [result, setResult] = useState(null);
    const [querySnapshot, setQuerySnapshot] = useState(null);
    const [loading, setLoading] = useState(false);
    const [status, setStatus] = useState(initial ? '请选择用途、柱撑型号和钢柱规格，再检索组合。' : '共享库尚未初始化，请管理员执行初始化。');
    const [draft, setDraft] = useState(null);
    const [editorMessage, setEditorMessage] = useState('');
    const [importPreview, setImportPreview] = useState(null);
    const [importMessage, setImportMessage] = useState('');
    const [libraryFeedback, setLibraryFeedback] = useState('');
    const [resultFeedback, setResultFeedback] = useState('');
    const [busy, setBusy] = useState(false);
    const [exporting, setExporting] = useState(false);
    const sequence = useRef(0);
    const queryLock = useRef(false);
    const mutationLock = useRef(false);
    const exportLock = useRef(false);
    const saveAttempt = useRef(null);
    const fileInput = useRef(null);
    const latestInput = useRef(null);
    const [focusLast, setFocusLast] = useState(false);
    const first = entries[0].criteria;
    const frames = library.frames.filter((f) => (!first.support_use || f.support_use === first.support_use) && (!first.diameter_mm || f.diameter_mm === first.diameter_mm));

    useEffect(() => { if (focusLast) { latestInput.current?.focus(); setFocusLast(false); } }, [entries.length, focusLast]);
    useEffect(() => {
        if (!draft) return undefined;
        const beforeUnload = (event) => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', beforeUnload);
        const unsubscribe = router.on('before', () => window.confirm('尚有未保存的支架库草稿，离开会丢失草稿。继续离开？'));
        return () => { window.removeEventListener('beforeunload', beforeUnload); unsubscribe(); };
    }, [!!draft]);

    function invalidate(message = '条件已修改，请重新检索。') {
        sequence.current++; queryLock.current = false;
        setResult(null); setQuerySnapshot(null); setResultFeedback(''); setLoading(false); setStatus(message);
    }
    function updateEntry(index, changes) {
        setEntries((old) => old.map((e, i) => i === index ? { ...e, ...changes } : e)); invalidate();
    }
    function add() {
        if (entries.length >= 20) return;
        setEntries((old) => [...old, { expression: '', criteria: { ...old[0].criteria } }]);
        setFocusLast(true); invalidate('已添加搭配，请填写组合后检索。');
    }
    async function run(nextData = data) {
        if (!nextData || queryLock.current) return;
        invalidate('正在检索…'); queryLock.current = true; setLoading(true);
        const id = sequence.current;
        const input = { library_revision: nextData.revision, request_fingerprint: requestId(), entries: entriesRef.current.map((e, i) => {
            if (!e.expression.trim()) throw new Error(`搭配${i + 1}：请填写组合或删除该搭配。`);
            return { expression: e.expression.trim(), criteria: { ...e.criteria, column_brace_model: preview(e.expression).single ? null : e.criteria.column_brace_model || null } };
        }) };
        try {
            const response = await request(urls.query, 'POST', input);
            const value = await response.json();
            if (id === sequence.current && value.request_fingerprint === input.request_fingerprint && value.library_revision === nextData.revision) {
                setResult(value.result); setQuerySnapshot(input); setStatus(value.result.bracing_complete ? '检索完成；数量与重量按下方范围统计。' : '部分柱撑搭配未收录，仅显示已计结果；完整数量和重量仍未知。');
            }
        } catch (error) { if (id === sequence.current) setStatus(error.message); }
        finally { if (id === sequence.current) { queryLock.current = false; setLoading(false); } }
    }
    async function submit(event) {
        event.preventDefault();
        try { await run(); } catch (error) { queryLock.current = false; setLoading(false); setStatus(error.message); }
    }
    async function reload() {
        if (draft && !window.confirm('加载最新保存库，当前草稿将保留但仍基于旧版。请核对后取消草稿，再重新编辑。继续？')) return;
        try { const next = await (await request(urls.library)).json(); setData(next); invalidate('已加载最新共享库，请重新检索。'); }
        catch (error) { setStatus(error.message); }
    }
    async function applySaved(next) {
        setData(next); setDraft(null); setImportPreview(null); invalidate('共享库已保存，检索已更新。');
        if (entriesRef.current.every((e) => e.expression.trim() && e.criteria.support_use && e.criteria.diameter_mm)) {
            try { await run(next); } catch (error) { queryLock.current = false; setLoading(false); setStatus(`库已保存，检索未完成：${error.message}`); }
        }
    }
    async function saveDraft() {
        if (!draft || mutationLock.current) return;
        const payload = { expected_revision: draft.revision, parts: draft.library.parts, frames: draft.library.frames, composition: draft.library.composition };
        const fingerprint = JSON.stringify(payload);
        if (saveAttempt.current?.fingerprint !== fingerprint) saveAttempt.current = { fingerprint, request_id: requestId() };
        mutationLock.current = true; setBusy(true); setEditorMessage('正在保存…');
        try {
            const next = await (await request(urls.save, 'PUT', { ...payload, request_id: saveAttempt.current.request_id })).json();
            saveAttempt.current = null; setLibraryFeedback(`共享库已保存，版本${next.revision}。`); await applySaved(next);
        } catch (error) { setEditorMessage(`未确认保存：${error.message} 若网络中断，可保持草稿重试以确认提交状态。`); }
        finally { mutationLock.current = false; setBusy(false); }
    }
    async function selectFile(event) {
        const file = event.target.files?.[0]; event.target.value = '';
        if (!file || mutationLock.current) return;
        if (draft) { setImportMessage('请先保存或取消正在编辑的数据库，再导入Excel。'); return; }
        if (!data) { setImportMessage('请先初始化共享库。'); return; }
        mutationLock.current = true; setBusy(true); setImportPreview(null); setImportMessage(`正在检查 ${file.name}…`);
        try {
            const body = new FormData(); body.append('file', file); body.append('expected_revision', data.revision);
            const p = await (await request(urls['imports.preview'], 'POST', body)).json();
            setImportPreview({ ...p, base_revision: data.revision, request_id: requestId() }); setImportMessage('检查通过，确认后才会更新共享库。');
        } catch (error) { setImportMessage(`导入未完成：${error.message} 当前数据库未改变。`); }
        finally { mutationLock.current = false; setBusy(false); }
    }
    async function commit() {
        if (!importPreview || mutationLock.current) return;
        mutationLock.current = true; setBusy(true);
        try {
            const next = await (await request(urls['imports.commit'], 'POST', { preview_token: importPreview.preview_token, expected_revision: importPreview.base_revision, request_id: importPreview.request_id })).json();
            setImportMessage(''); setLibraryFeedback(`Excel导入成功，共享库版本${next.revision}。`); await applySaved(next);
        } catch (error) { setImportMessage(`未确认导入：${error.message}`); }
        finally { mutationLock.current = false; setBusy(false); }
    }
    async function exportFile(kind) {
        if (exportLock.current || (kind === 'result' && !querySnapshot)) return;
        if (draft && kind !== 'result') { setLibraryFeedback('请先保存或取消正在编辑的数据库，再导出当前库。'); return; }
        const names = { result: result?.bracing_complete ? '支架构件明细.xlsx' : '支架构件明细_部分结果.xlsx', library: '支架库_当前数据库.xlsx', html: '钢箱梁工作台_更新版.html', template: '支架库_导入模板.xlsx' };
        const report = kind === 'result' ? setResultFeedback : setLibraryFeedback;
        const snapshot = querySnapshot;
        const version = data?.revision;
        exportLock.current = true; setExporting(true);
        try {
            await saveDownload({ filename: names[kind], report, generate: () => kind === 'result' ? request(urls['exports.result'], 'POST', snapshot)
                : request(kind === 'template' ? urls.template : `${urls[`exports.${kind}`]}?library_revision=${version}`) });
        } catch (error) {
            if (error.status === 409) invalidate(error.message);
        } finally { exportLock.current = false; setExporting(false); }
    }
    return <Layout hideHeader><Head title="钢箱梁工作台" /><main className="support-library" id="support-top">

        <section className="support-overview"><div className="support-hero-copy"><nav className="support-local-nav" aria-label="支架库页面导航"><a href="#support-search">组合检索</a><a href="#support-catalog">已有搭配</a></nav><h1>钢箱梁工作台</h1><p>选定用途、柱撑型号和钢柱规格，用“高度@横向间距@纵向间距/组数”输入支架组合；单柱可直接写“高度/组数”。</p><div className="support-tags"><span>来源：{data?.source_filename || '尚未初始化'}</span><span>库中数量按每个柱撑面</span><span>备注带孔独立区分</span></div><p className="muted">版本 {data?.revision || 0} · {data?.updated_at ? new Date(data.updated_at).toLocaleString('zh-CN') : '未保存'}</p></div>
            <div className="support-hero-library"><div className="support-library-counts"><p><strong>{library.frames.length}</strong>种支架搭配<br />{[...new Set(library.frames.map((f) => f.support_use))].join(' / ') || '暂无搭配'}</p><p><strong>{library.parts.length}</strong>条构件记录<br />{library.parts.filter((p) => p.kind === '钢柱').length}条钢柱 / {library.parts.filter((p) => p.kind === '柱撑').length}条柱撑</p></div><p>C14A、C16A均适用原表搭配；按连续跨距及高度分段展开，中间排柱撑一并计入。</p>
                <div className="support-actions">{capabilities.manage && <><button disabled={!data || busy || !!draft} onClick={() => { setImportMessage(''); fileInput.current?.click(); }}>从Excel导入支架库</button><input ref={fileInput} type="file" accept=".xlsx" aria-label="选择Excel支架库" hidden onChange={selectFile} /></>}
                    {capabilities.export && <button disabled={!data || exporting} onClick={() => exportFile('library')}>导出当前数据库 Excel</button>}
                    {capabilities.manage && <button disabled={!data || busy || !!importPreview} onClick={() => { setDraft({ revision: data.revision, library: clone(library) }); setEditorMessage(''); }}>修改当前数据库</button>}
                    {capabilities.export && <><button disabled={!data || exporting} onClick={() => exportFile('html')}>下载更新版HTML</button><button disabled={exporting} onClick={() => exportFile('template')}>下载Excel格式模板</button></>}
                    <button disabled={busy} onClick={reload}>加载最新版本</button></div><p className="muted">平台共享保存；原Excel不改。离线HTML是版本快照，离线修改不自动同步。</p><p role="status" aria-live="polite">{libraryFeedback}</p>
            </div></section>
        {(importMessage || importPreview) && <section className="support-card" aria-label="Excel支架库导入"><h2>Excel支架库导入</h2><p>沿用原库两张数据表，支持合并单元格；数量按一个柱撑面。</p><p role="status" aria-live="polite">{importMessage}</p>{importPreview && <><p>{importPreview.filename}：构件新增{importPreview.stats.partsAdded}条、更新{importPreview.stats.partsUpdated}条；搭配新增{importPreview.stats.framesAdded}种、更新{importPreview.stats.framesUpdated}种；每面用量{importPreview.compositionCount}条。</p><p className="support-warning">相同构件的单重、螺栓等字段及同条件搭配以本次Excel为准；空值也会覆盖。共享构件更新会影响已有搭配。未出现的搭配保留。</p>{importPreview.warnings.map((w, i) => <p key={i}>{w}</p>)}</>}
            <div className="support-actions"><button className="primary" disabled={!importPreview || busy} onClick={commit}>确认导入并更新</button><button disabled={busy} onClick={() => { setImportPreview(null); setImportMessage(''); }}>取消</button></div></section>}
        {draft && <LibraryEditor draft={draft.library} savedLibrary={library} onChange={(next) => setDraft({ ...draft, library: next })} onSave={saveDraft} onCancel={() => { setDraft(null); saveAttempt.current = null; }} busy={busy} message={editorMessage} />}
        <section id="support-search"><p className="support-section-label">01 / 组合检索</p><h2>输入支架组合</h2><p>同高度按钢柱规格和柱间距区分；不同孔位的柱撑分别统计。</p><div className="support-query-layout"><form className="support-card" onSubmit={submit}>
            {entries.map((entry, index) => <Combination key={index} entry={entry} index={index} inputRef={index === entries.length - 1 ? latestInput : undefined} update={(changes) => updateEntry(index, changes)} remove={() => { setEntries((old) => old.filter((_, i) => i !== index)); invalidate(); }} />)}
            <div className="support-actions"><button type="button" disabled={entries.length >= 20} onClick={add}>＋ 添加搭配</button><button type="submit" className="primary" disabled={!data || loading}>{loading ? '正在检索…' : '检索全部搭配 →'}</button></div>
            <div className="support-presets">{presets.map(([value, label]) => <button key={value} type="button" onClick={() => updateEntry(0, { expression: value })}>{label}</button>)}</div><p className="muted">单位为米；+表示高度分段叠接或连续跨。/后为组数，省略默认1组。多个独立组合用空格、分号或换行，例如6/2 6/4。各组独立，不自动相连。</p><p className="support-status" role="status" aria-live="polite">{status}</p>
        </form><aside className="support-card"><h3>本次包含范围</h3><p>每组柱位＝(横向跨数+1) × (纵向跨数+1)。逐高度段、逐跨匹配库中柱撑，所有跨和中间排均计入。</p><p>每段钢柱根数＝柱位/组 × 组数。横向每跨面数＝纵向跨数+1；纵向每跨面数＝横向跨数+1。</p><p>柱撑螺栓按根累计；钢柱仅计内部接头，每个接头一次，柱底柱顶不计。</p><p>C14a：14.535 kg/m；C16a：17.240 kg/m。每根柱撑使用一根槽钢：长度mm ÷ 1000 × 米重。原库有效单重优先；理论重未扣孔，不含连接板等附件。</p><p>M22×80普通螺栓按0.44 kg/套估算；每套含1根螺栓、1个螺母、2片普通平垫圈。</p><p>缺搭配保留钢柱和已匹配柱撑；未知不当0。底座、连接板和组间联系撑未计入。只检索既有搭配，不自动设计或验算承载力。</p></aside></div></section>
        <ResultTable result={result} canExport={capabilities.export} exporting={exporting} onExport={() => exportFile('result')} feedback={resultFeedback} />
        <section id="support-catalog" className="support-card"><p className="support-section-label">02 / 已有搭配</p><h2>支架搭配目录</h2><p>按搭配1的用途、钢柱规格筛选，当前显示{frames.length} / {library.frames.length}种搭配。C14A、C16A均沿用每面数量。</p><div className="support-table-wrap"><table><thead><tr>{['搭配编号', '用途', '钢柱', '高度m', '柱间距m', '每个柱撑面：长度mm × 根数'].map((h) => <th key={h}>{h}</th>)}</tr></thead><tbody>{frames.map((f) => <tr key={f.id}><td>{f.id}</td><td>{f.support_use}</td><td>φ{f.diameter_mm}</td><td>{f.height_mm / 1000}</td><td>{f.spacing_mm / 1000}</td><td>{library.composition.filter((d) => d.frame_id === f.id).map((d) => `${d.length_mm}${d.hole === '带中孔' ? '（带中孔）' : ''} × ${d.per_frame}`).join('；')}</td></tr>)}</tbody></table></div><p>{frames.length ? '库中数量对应1个柱撑面，重复跨及中间排均保留。' : '搭配资料待补充。'}</p></section>
        <footer className="support-section-head"><p>钢箱梁支架库 · 共享版本资料 · 估算范围以本页说明为准</p><a href="#support-top">返回顶部 ↑</a></footer>
    </main></Layout>;
}
