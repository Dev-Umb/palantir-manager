import { useEffect, useRef, useState } from 'react';
import { FileUp, LoaderCircle, X } from 'lucide-react';
import './ContractIntake.css';

async function request(url, { body, ...options } = {}) {
    const form = body instanceof FormData;
    const response = await fetch(url, {
        ...options,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', ...(!form && body ? { 'Content-Type': 'application/json' } : {}) },
        body: body ? (form ? body : JSON.stringify(body)) : undefined,
    });
    const data = await response.json();
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join('；') || data.message || '操作未完成，请重试。');
    return data;
}

const valueText = (value) => value === null || value === undefined || value === '' ? '未填写' : String(value);
const fieldsFrom = (extraction = {}) => ({ amount: extraction?.amount ?? '', ctype: extraction?.ctype ?? '', signed_date: extraction?.signed_date ?? '', contract_qty: extraction?.contract_qty ?? '' });

export default function ContractIntake({ onClose }) {
    const [intake, setIntake] = useState(null);
    const [recent, setRecent] = useState([]);
    const [file, setFile] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [search, setSearch] = useState('');
    const [projects, setProjects] = useState([]);
    const [truncated, setTruncated] = useState(false);
    const [project, setProject] = useState(null);
    const [contractChoice, setContractChoice] = useState('');
    const [fields, setFields] = useState(fieldsFrom());
    const [weight, setWeight] = useState('');
    const [updateAmount, setUpdateAmount] = useState(false);
    const [preview, setPreview] = useState(null);
    const closeRef = useRef(null);
    const projectRequest = useRef(0);
    const processing = ['staged', 'analyzing'].includes(intake?.status);

    useEffect(() => {
        const prior = document.activeElement;
        closeRef.current?.focus();
        const controller = new AbortController();
        request('/ai/contracts', { signal: controller.signal }).then((data) => setRecent(data.intakes || [])).catch((e) => { if (e.name !== 'AbortError') setError(e.message); });
        return () => { controller.abort(); prior?.focus?.(); };
    }, []);

    useEffect(() => {
        if (!processing) return undefined;
        let disposed = false;
        let timer;
        const controller = new AbortController();
        async function poll() {
            try {
                const data = await request(`/ai/contracts/${intake.id}`, { signal: controller.signal });
                if (!disposed) { setIntake(data); setError(''); }
            } catch (e) { if (!disposed) setError(e.message); }
            finally { if (!disposed) timer = setTimeout(poll, 1500); }
        }
        timer = setTimeout(poll, 500);
        return () => { disposed = true; clearTimeout(timer); controller.abort(); };
    }, [intake?.id, processing]);

    useEffect(() => {
        setPreview(null); setProject(null); setContractChoice(''); setUpdateAmount(false);
        setFields(fieldsFrom(intake?.extraction)); setWeight(intake?.extraction?.weight_tonnes ?? '');
        setSearch(intake?.extraction?.project_no || intake?.extraction?.project_name || '');
    }, [intake?.id, intake?.status]);

    useEffect(() => {
        if (intake?.status !== 'review') return undefined;
        const controller = new AbortController();
        const timer = setTimeout(() => {
            request(`/ai/contracts/projects?search=${encodeURIComponent(search)}`, { signal: controller.signal })
                .then((data) => { setProjects(data.projects); setTruncated(data.truncated); })
                .catch((e) => { if (e.name !== 'AbortError') setError(e.message); });
        }, 200);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [intake?.status, search]);

    async function act(callback) {
        setBusy(true); setError('');
        try { await callback(); } catch (e) { setError(e.message); } finally { setBusy(false); }
    }

    function upload(event) {
        event.preventDefault();
        if (!file) return;
        if (file.size > 20 * 1024 * 1024) { setError('文件不能超过 20 MB。'); return; }
        act(async () => { const body = new FormData(); body.append('file', file); setIntake(await request('/ai/contracts', { method: 'POST', body })); });
    }

    async function selectProject(id) {
        const sequence = ++projectRequest.current;
        setProject(null); setContractChoice(''); setPreview(null); setUpdateAmount(false);
        if (!id) return;
        await act(async () => { const next = await request(`/ai/contracts/projects/${id}`); if (sequence === projectRequest.current) setProject(next); });
    }

    function selectContract(id) {
        setContractChoice(id); setPreview(null);
        const existing = project?.contracts.find((item) => item.id === id)?.fields || {};
        const extracted = intake.extraction || {};
        setFields(Object.fromEntries(Object.keys(fieldsFrom()).map((key) => [key, extracted[key] ?? existing[key] ?? ''])));
    }

    function review(event) {
        event.preventDefault();
        act(async () => setPreview(await request(`/ai/contracts/${intake.id}/preview`, { method: 'POST', body: {
            project_id: project.id, contract_id: contractChoice === '__new__' ? null : contractChoice,
            fields: Object.fromEntries(Object.entries(fields).map(([key, value]) => [key, value === '' ? null : value])),
            update_project_amount: updateAmount, project_weight: weight === '' ? null : weight,
        } })));
    }

    function confirm() {
        act(async () => {
            try {
                const result = await request(`/ai/contracts/${intake.id}/confirm`, { method: 'POST', body: { token: preview.token } });
                setIntake((current) => ({ ...current, status: 'confirmed', result }));
            } catch (e) { setPreview(null); throw e; }
        });
    }

    function keyDown(event) {
        if (event.key === 'Escape' && !busy) onClose();
        if (event.key !== 'Tab') return;
        const focusable = [...event.currentTarget.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled), a[href]')];
        const first = focusable[0]; const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }

    return <div className="ai-contract-overlay" onKeyDown={keyDown}>
        <section className="ai-contract-panel" role="dialog" aria-modal="true" aria-labelledby="contract-intake-title">
            <header><div><h2 id="contract-intake-title">上传项目合同</h2><p>识别内容 · 核对差异 · 确认归档</p></div><button ref={closeRef} type="button" className="icon-button" onClick={onClose} disabled={busy} aria-label="关闭合同上传"><X size={20} /></button></header>
            <div className="ai-contract-body">
                {error && <p role="alert" className="ai-contract-error">{error}</p>}
                {!intake && <>
                    <form onSubmit={upload} className="ai-contract-upload">
                        <FileUp size={30} /><label>选择合同文件<input aria-label="合同文件" type="file" accept=".pdf,.jpg,.jpeg,.png" disabled={busy} onChange={(e) => setFile(e.target.files?.[0] || null)} /></label>
                        <p>PDF、JPG 或 PNG，最大 20 MB。文件将提交给当前 AI 服务识别，确认前不改业务数据。</p>
                        <button className="action-button" disabled={!file || busy}>{busy ? '正在上传…' : '上传并识别'}</button>
                    </form>
                    {recent.length > 0 && <div className="ai-contract-recent"><h3>最近上传</h3>{recent.map((item) => <button type="button" key={item.id} onClick={() => setIntake(item)}>{item.file_name}<span>{item.status === 'confirmed' ? '已归档' : '继续处理'}</span></button>)}</div>}
                </>}
                {processing && <div className="ai-contract-upload" role="status"><LoaderCircle size={26} className="animate-spin" /><strong>正在识别 {intake.file_name}</strong><p>可关闭窗口，稍后从“最近上传”继续。确认前不会修改项目。</p></div>}
                {intake?.status === 'failed' && <div><p role="alert">{intake.error}</p><button className="action-button" disabled={busy} onClick={() => act(async () => setIntake(await request(`/ai/contracts/${intake.id}/retry`, { method: 'POST' })))}>重新识别</button></div>}
                {intake?.status === 'review' && !preview && <form onSubmit={review}>
                    <p><strong>{intake.file_name}</strong></p>
                    <p className="ai-contract-note">识别项目：{valueText(intake.extraction?.project_name)}　客户：{valueText(intake.extraction?.customer_name)}　原文件合同编号：{valueText(intake.extraction?.contract_no)}</p>
                    {!!intake.extraction?.warnings?.length && <ul className="ai-contract-warning">{intake.extraction.warnings.map((note, i) => <li key={i}>{note}</li>)}</ul>}
                    {!!intake.extraction?.evidence?.length && <details><summary>查看识别依据</summary><ul>{intake.extraction.evidence.map((note, i) => <li key={i}>{note}</li>)}</ul></details>}
                    <div className="ai-contract-fields">
                        <label>搜索归档项目<input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="输入项目名称或编号" /></label>
                        <label>归档项目<select aria-label="归档项目" value={project?.id || ''} onChange={(e) => selectProject(e.target.value)} disabled={busy}><option value="">请选择项目</option>{projects.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}</select></label>
                        {truncated && <p>匹配较多，请输入更具体的项目名称或编号。</p>}
                        {!projects.length && <p>没有匹配的可维护项目，可调整搜索词；新项目请先在项目主档创建。</p>}
                        {project && <label>归档合同<select aria-label="归档合同" value={contractChoice} onChange={(e) => selectContract(e.target.value)}><option value="">请选择合同或明确新增</option>{project.contracts.map((c) => <option key={c.id} value={c.id}>{c.code} · {valueText(c.fields.amount)} 元</option>)}<option value="__new__">新增一份合同</option></select></label>}
                        <label>本份合同金额（元）<input required type="number" step="0.01" value={fields.amount} onChange={(e) => setFields({ ...fields, amount: e.target.value })} /></label>
                        <label>合同类型<select value={fields.ctype} onChange={(e) => setFields({ ...fields, ctype: e.target.value })}><option value="">未识别 / 未填写</option>{['销售合同', '加工合同', '补充协议'].map((v) => <option key={v}>{v}</option>)}</select></label>
                        <label>签订日期<input type="date" value={fields.signed_date} onChange={(e) => setFields({ ...fields, signed_date: e.target.value })} /></label>
                        <label>合同数量<input type="number" min="0" step="any" value={fields.contract_qty} onChange={(e) => setFields({ ...fields, contract_qty: e.target.value })} /></label>
                        <label>项目合同重量（吨，可留空保持原值）<input type="number" min="0" step="any" value={weight} onChange={(e) => setWeight(e.target.value)} /></label>
                    </div>
                    {project?.can_update_project_amount ? <label className="ai-contract-checkbox"><input type="checkbox" checked={updateAmount} onChange={(e) => setUpdateAmount(e.target.checked)} />同时按全部合同合计更新项目主档合同金额（当前 {valueText(project.contract_amount)} 元）</label> : project && <p className="ai-contract-note">项目主档已有合同金额会保留；当前账号不能覆盖该财务字段。主档为空时沿用合同自动补齐规则。</p>}
                    <button className="action-button" disabled={busy || !project || !contractChoice}>{busy ? '正在生成差异…' : '预览归档与修改差异'}</button>
                </form>}
                {preview && intake?.status === 'review' && <div>
                    <h3>确认本次归档</h3><p>{preview.project.code} · {preview.project.name} / {preview.contract}</p><p>附件：{preview.file}</p>
                    <table className="ai-contract-diff"><thead><tr><th>字段</th><th>当前值</th><th>确认后</th></tr></thead><tbody>{preview.changes.map((c, i) => <tr key={i}><th>{c.label}</th><td>{valueText(c.before)}</td><td>{valueText(c.after)}</td></tr>)}</tbody></table>
                    <ul>{preview.effects.map((effect) => <li key={effect}>{effect}</li>)}</ul>
                    <div className="ai-contract-actions"><button type="button" className="ghost-button" disabled={busy} onClick={() => setPreview(null)}>返回核对</button><button type="button" className="action-button" disabled={busy} onClick={confirm}>{busy ? '正在归档…' : '确认归档并更新'}</button></div>
                </div>}
                {intake?.status === 'confirmed' && <div role="status"><h3>合同已归档</h3><p>{intake.result?.message}</p><p>合同编号：{intake.result?.contract_code}</p>{intake.result?.project_url && <p><a href={intake.result.project_url}>查看项目及合同附件</a></p>}<button className="ghost-button" type="button" onClick={() => { setIntake(null); setFile(null); }}>继续上传另一份</button></div>}
            </div>
        </section>
    </div>;
}
