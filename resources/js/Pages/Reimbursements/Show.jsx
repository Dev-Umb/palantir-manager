import { Head, Link } from '@inertiajs/react';
import { Eye, Download } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Layout from '../../Components/Layout';
import { useDialogFocus } from '../../Components/useDialogFocus';
import { request, money, Status } from './shared';
function Preview({ file, close }) {
    const panel = useRef(null);
    const [url, setUrl] = useState(''); const [error, setError] = useState('');
    useDialogFocus(true, panel);
    useEffect(() => { let active = true; let blobUrl; request(file.preview_url).then((r) => r.blob()).then((blob) => { blobUrl = URL.createObjectURL(blob); if (active) setUrl(blobUrl); else URL.revokeObjectURL(blobUrl); }).catch((e) => { if (active) setError(e.message); }); return () => { active = false; if (blobUrl) URL.revokeObjectURL(blobUrl); }; }, [file.preview_url]);
    return <div className="oa-overlay" onClick={close}><section ref={panel} className="oa-preview" role="dialog" aria-modal="true" aria-label="发票预览" onClick={(e) => e.stopPropagation()} onKeyDown={(e) => { if (e.key === 'Escape') close(); }}><header className="oa-head"><strong>{file.name}</strong><button onClick={close}>关闭</button></header>{error ? <p role="alert">{error}</p> : !url ? <p>加载中…</p> : file.mime === 'application/pdf' ? <iframe title={file.name} src={url} /> : <img alt={file.name} src={url} />}</section></div>;
}
export default function Show({ record }) {
    const [r, setRecord] = useState(record); const [preview, setPreview] = useState(null); const [note, setNote] = useState(''); const [busy, setBusy] = useState(false); const [message, setMessage] = useState(''); const lock = useRef(false);
    useEffect(() => { setRecord(record); setNote(''); }, [record]);
    async function decide(decision) {
        if (lock.current) return;
        if (decision === 'reject' && !note.trim()) { setMessage('驳回时请填写审批意见。'); return; }
        lock.current = true; setBusy(true); setMessage('');
        try { const response = await request(`${r.url}/decision`, 'POST', { node_index: r.node_index, decision, note }); setRecord((await response.json()).record); setNote(''); setMessage('审批已保存。'); }
        catch (e) { setMessage(e.message); } finally { lock.current = false; setBusy(false); }
    }
    async function download(url, name) {
        if (lock.current) return; lock.current = true; setBusy(true); setMessage('正在准备下载…');
        try { const response = await request(url); const blob = await response.blob(); const target = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = target; a.download = name; a.click(); setTimeout(() => URL.revokeObjectURL(target), 10000); setMessage('已发起下载，请查看浏览器下载记录。'); }
        catch (e) { setMessage(e.message); } finally { lock.current = false; setBusy(false); }
    }
    return <Layout hideHeader><Head title={r.title} /><main className="oa oa-detail"><header className="oa-head"><div><Link href="/reimbursements">← 报销审批</Link><h1>{r.title}</h1><span>BX-{r.reimbursement_id} · V{r.number}</span></div><Status value={r.status} /></header>
        <section className="oa-panel"><h2>申请信息</h2><dl className="oa-info"><div><dt>发起人</dt><dd>{r.owner?.name}</dd></div><div><dt>报销类型</dt><dd>{r.type}</dd></div><div><dt>报销总额</dt><dd className="oa-amount">¥{money(r.total_cents)}</dd></div><div><dt>提交时间</dt><dd>{new Date(r.created_at).toLocaleString('zh-CN')}</dd></div></dl><p className="oa-note">{r.note}</p><div className="oa-actions"><span>提交版本</span>{r.versions.map((v) => <Link key={v.number} href={v.url} aria-current={v.number === r.number ? 'page' : undefined}>V{v.number}</Link>)}</div></section>
        <details className="oa-panel" open><summary>费用明细 <span>¥{money(r.total_cents)}</span></summary><button disabled={busy} onClick={() => download(r.zip_url, `BX-${r.reimbursement_id}-V${r.number}-发票.zip`)}>一键下载全部发票</button>{r.items.map((item, i) => <article className="oa-expense" key={i}><div className="oa-head"><strong>{i + 1}. {item.description}</strong><b>¥{money(item.cents)}</b></div>{item.attachments.map((file) => <div className="oa-file" key={file.id}><span>{file.name}<small>{file.mime === 'application/pdf' ? 'PDF' : '图片'} · {(file.size / 1024).toFixed(1)} KB</small></span><button aria-label={`预览 ${file.name}`} onClick={() => setPreview(file)}><Eye size={17} /></button><button disabled={busy} aria-label={`下载 ${file.name}`} onClick={() => download(file.download_url, file.name)}><Download size={17} /></button></div>)}</article>)}</details>
        <details className="oa-panel" open><summary>审批进度</summary>{r.blocked && <p role="alert">当前节点存在失效审批人，请联系管理员处理；流程不会自动跳过。</p>}{r.flow.nodes.map((node, i) => <div className={`oa-node ${i < r.node_index ? 'done' : i === r.node_index ? 'current' : ''}`} key={i}><h3>{i + 1}. {node.name} <small>{node.mode === 'all' ? '会签' : '或签'} · {i < r.node_index ? '已通过' : i === r.node_index ? r.status === 'rejected' ? '已驳回' : '处理中' : '待处理'}</small></h3><p>{node.people.map((p) => p.name).join('、')}</p>{r.decisions.filter((d) => d.node === i).map((d) => <p key={d.user_id}>{d.name} · {d.decision === 'approve' ? '同意' : '驳回'} · {new Date(d.at).toLocaleString('zh-CN')}{d.note && <span className="oa-note">：{d.note}</span>}</p>)}</div>)}</details>
        <details className="oa-panel" open><summary>知会人员</summary><p>流程固定：{r.flow.cc.map((p) => p.name).join('、') || '无'}</p><p>申请追加：{r.cc.map((p) => p.name).join('、') || '无'}</p></details>
        <section className="oa-panel"><h2>审批处理</h2>{r.can_decide ? <><label>审批意见<textarea value={note} maxLength={2000} onChange={(e) => setNote(e.target.value)} placeholder="驳回时必填" /></label><div className="oa-actions"><button disabled={busy} onClick={() => decide('reject')}>驳回</button><button className="oa-primary" disabled={busy} onClick={() => decide('approve')}>同意</button></div></> : <p>{r.status === 'approved' ? '审批已通过，不代表已付款。' : r.status === 'rejected' ? '本轮已结束，申请人可在移动端修改后重新提交。' : '当前账号无需处理此节点。'}</p>}</section><p role="status" className="oa-feedback">{message}</p>{preview && <Preview file={preview} close={() => setPreview(null)} />}</main></Layout>;
}
