import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Layout from '../../Components/Layout';
import { requestId } from '../SupportLibrary/api';
import { money, request } from './shared';

export function amountCents(value) {
    if (!/^(?:0|[1-9]\d{0,6})(\.\d{1,2})?$/.test(value || '')) return null;
    const [whole, fraction = ''] = value.split('.');
    const cents = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
    return cents > 0 ? cents : null;
}
const emptyItem = () => ({ description: '', amount: '', invoice_date: '', attachments: [] });
function initialValues(draft, source, types) {
    const data = draft?.payload || source;
    return data ? {
        title: data.title || '', type: data.type || types[0], note: data.note || '',
        items: data.items.length ? data.items.map(item => ({ ...item, description: item.description || '', amount: item.amount || '', invoice_date: item.invoice_date || '' })) : [emptyItem()],
        cc: data.cc.map(person => typeof person === 'object' ? person.id : Number(person)),
        ...(source ? { reimbursement_id: source.reimbursement_id, expected_version: source.number } :
            data.reimbursement_id ? { reimbursement_id: data.reimbursement_id, expected_version: data.expected_version } : {}),
    } : { title: '', type: types[0], note: '', items: [emptyItem()], cc: [] };
}
function payloadOf(values) {
    return { ...values, items: values.items.map(item => ({
        description: item.description, amount: item.amount, invoice_date: item.invoice_date || null,
        attachments: item.attachments.map(file => file.id),
        ...(item.evidence_source ? { evidence_source: item.evidence_source } : {}),
    })) };
}

export default function Form({ draft = null, source = null, types = [], users = [] }) {
    const [values, setValues] = useState(() => initialValues(draft, source, types));
    const [session] = useState(() => ({ id: draft?.id || requestId(), revision: draft?.revision || 0, key: requestId() }));
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState(draft ? '草稿已保存' : '填写费用并上传附件后提交');
    const [error, setError] = useState('');
    const [conflict, setConflict] = useState(false);
    const [failedUpload, setFailedUpload] = useState(null);
    const [pendingSubmission, setPendingSubmission] = useState(null);
    const dirty = useRef(false);
    const lock = useRef(false);
    const saved = useRef(draft ? JSON.stringify(payloadOf(initialValues(draft, source, types))) : null);
    const blocked = busy || conflict || !!pendingSubmission || !!failedUpload;
    const total = values.items.reduce((sum, item) => sum + (amountCents(item.amount) || 0), 0);

    useEffect(() => {
        const beforeUnload = event => { if (dirty.current || lock.current) { event.preventDefault(); event.returnValue = ''; } };
        window.addEventListener('beforeunload', beforeUnload);
        const stop = router.on?.('before', event => {
            if ((dirty.current || lock.current) && !window.confirm('当前有未保存或未完成的操作，确定离开？')) event.preventDefault();
        });
        return () => { window.removeEventListener('beforeunload', beforeUnload); stop?.(); };
    }, []);
    function change(next) { dirty.current = true; setValues(next); setMessage('有未保存的修改'); }
    function changeItem(index, key, value) {
        change({ ...values, items: values.items.map((item, i) => i === index ? { ...item, [key]: value } : item) });
    }
    function acceptDraft(record) {
        session.revision = record.revision;
        const next = initialValues(record, null, types);
        setValues(next); saved.current = JSON.stringify(payloadOf(next)); dirty.current = false;
        setMessage('草稿已保存，可在小程序继续编辑');
        return next;
    }
    function reportFailure(failure) {
        setError(failure.message);
        if (failure.status === 409) setConflict(true);
    }
    async function saveDraft() {
        const payload = payloadOf(values);
        if (session.revision && saved.current === JSON.stringify(payload)) return;
        const response = await request(session.revision ? `/reimbursements/drafts/${session.id}` : '/reimbursements/drafts', session.revision ? 'PUT' : 'POST', {
            id: session.id, revision: session.revision, payload,
        });
        acceptDraft((await response.json()).draft);
    }
    async function save() {
        if (lock.current || blocked) return;
        lock.current = true; setBusy(true); setError('');
        try { await saveDraft(); } catch (failure) { reportFailure(failure); }
        finally { lock.current = false; setBusy(false); }
    }
    async function upload(index, file, retry = null) {
        if (!file || lock.current || conflict || pendingSubmission) return;
        if (!retry && values.items[index].attachments.length >= 10) { setError('每项费用最多上传10个附件'); return; }
        const context = retry?.context || { id: session.id, revision: session.revision, operation: requestId(), item: index, payload: payloadOf(values) };
        lock.current = true; dirty.current = true; setBusy(true); setError('');
        try {
            const body = new FormData(); body.append('file', file); body.append('draft_context', JSON.stringify(context));
            const response = await request('/reimbursements/uploads', 'POST', body);
            acceptDraft((await response.json()).draft); setFailedUpload(null);
        } catch (failure) {
            reportFailure(failure);
            setFailedUpload({ index, file, context, uncertain: !failure.status || failure.status >= 500 });
        } finally { lock.current = false; setBusy(false); }
    }
    async function submit(event) {
        event.preventDefault();
        if (lock.current || conflict || failedUpload) return;
        if (!pendingSubmission && values.items.some(item => !item.description.trim() || Array.from(item.description).length > 20 || !amountCents(item.amount) || !item.attachments.length)) {
            setError('请填写每项费用的说明（不超过20字）、有效金额，并上传至少一个附件。'); return;
        }
        lock.current = true; setBusy(true); setError('');
        let body = pendingSubmission;
        try {
            if (!body) {
                await saveDraft();
                body = { ...payloadOf(values), submission_key: session.key, draft_id: session.id, draft_revision: session.revision };
                setPendingSubmission(body);
            }
            const response = await request('/reimbursements', 'POST', body);
            const record = (await response.json()).record;
            dirty.current = false; lock.current = false; setPendingSubmission(null);
            router.visit(record.url);
        } catch (failure) {
            reportFailure(failure);
            if (failure.status && failure.status < 500) setPendingSubmission(null);
            else if (body) setError(`${failure.message} 提交结果尚未确认，请点击“重试提交”，不会重复创建申请。`);
        } finally { lock.current = false; setBusy(false); }
    }
    return <Layout hideHeader><Head title="发起报销" /><main className="oa oa-form">
        <header className="oa-head"><div><Link href="/reimbursements">← 报销申请</Link><h1>{values.reimbursement_id ? '修改后重新提交' : '发起报销'}</h1><p>手工填写费用并上传附件</p></div><Link href="/reimbursements/drafts">我的草稿</Link></header>
        <form onSubmit={submit}>
            <fieldset disabled={blocked}>
                <section className="oa-panel"><h2>申请信息</h2>
                    <label>报销标题<input required maxLength={120} value={values.title} onChange={e => change({ ...values, title: e.target.value })} /></label>
                    <label>报销类型<select value={values.type} onChange={e => change({ ...values, type: e.target.value })}>{types.map(type => <option key={type}>{type}</option>)}</select></label>
                    <label>报销说明<textarea required maxLength={4000} value={values.note} onChange={e => change({ ...values, note: e.target.value })} /></label>
                </section>
                <section className="oa-panel"><h2>费用与附件</h2>
                    {values.items.map((item, index) => <article className="oa-expense" key={index}>
                        <div className="oa-head"><h3>费用 {index + 1}</h3>{values.items.length > 1 && <button type="button" onClick={() => change({ ...values, items: values.items.filter((_, i) => i !== index) })}>删除费用 {index + 1}</button>}</div>
                        <div className="oa-form-grid">
                            <label>费用说明 {index + 1}<input required maxLength={20} placeholder="不超过20字" value={item.description} onChange={e => changeItem(index, 'description', Array.from(e.target.value).slice(0, 20).join(''))} /></label>
                            <label>金额 {index + 1}（元）<input required inputMode="decimal" value={item.amount} onChange={e => changeItem(index, 'amount', e.target.value)} /></label>
                            <label>开票日期 {index + 1}（选填）<input type="date" value={item.invoice_date} onChange={e => changeItem(index, 'invoice_date', e.target.value)} /></label>
                        </div>
                        {Array.from(item.description).length > 20 && <p role="alert">原费用说明超过20字，请确认并缩短后提交；原内容尚未修改。</p>}
                        {item.attachments.map(file => <div className="oa-file" key={file.id}><a href={file.url || file.preview_url} target="_blank" rel="noreferrer">{file.name}</a><button type="button" aria-label={`移除 ${file.name}`} onClick={() => changeItem(index, 'attachments', item.attachments.filter(a => a.id !== file.id))}>移除</button></div>)}
                        <label className="oa-upload">上传附件 {index + 1}<input type="file" accept="application/pdf,image/jpeg,image/png" disabled={item.attachments.length >= 10} onChange={e => { const file = e.target.files?.[0]; e.target.value = ''; upload(index, file); }} /></label>
                        <p>支持 PDF、JPG、PNG，单个不超过20MB，每项最多10个。</p>
                    </article>)}
                    <button type="button" disabled={values.items.length >= 50} onClick={() => change({ ...values, items: [...values.items, emptyItem()] })}>＋ 添加费用</button>
                </section>
                <section className="oa-panel"><h2>追加知会人</h2><div className="oa-checks">{users.map(user => <label key={user.id}><input type="checkbox" checked={values.cc.includes(user.id)} onChange={e => change({ ...values, cc: e.target.checked ? [...values.cc, user.id] : values.cc.filter(id => id !== user.id) })} />{user.name}</label>)}</div></section>
            </fieldset>
            {error && <p role="alert" className="oa-form-error">{error}</p>}
            {conflict && <p>当前输入已保留，请核对后<Link href={`/reimbursements/create?draft=${session.id}`}>重新打开草稿</Link>，不会覆盖另一端的修改。</p>}
            {failedUpload && !conflict && <div className="oa-actions"><span>{failedUpload.file.name} 上传未完成</span><button type="button" disabled={busy} onClick={() => upload(failedUpload.index, failedUpload.file, failedUpload)}>重试上传</button>{!failedUpload.uncertain && <button type="button" disabled={busy} onClick={() => setFailedUpload(null)}>重新选择附件</button>}</div>}
            <footer className="oa-panel oa-form-footer"><strong>合计 ¥{money(total)}</strong><span role="status">{busy ? '正在处理…' : message}</span><div className="oa-actions"><button type="button" disabled={blocked} onClick={save}>保存草稿</button><button className="oa-primary" disabled={busy || conflict || !!failedUpload} type="submit">{pendingSubmission ? '重试提交' : '提交申请'}</button></div></footer>
        </form>
    </main></Layout>;
}
