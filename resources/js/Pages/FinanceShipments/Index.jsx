import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Layout from '../../Components/Layout';
import ComboBox from '../../Components/ComboBox';
import { number, request } from './ledger';
import './finance.css';

export default function Index({ projects, projectOptions = [], canUnlink = false }) {
    const [owner, setOwner] = useState(() => new URLSearchParams(window.location.search).get('owner'));
    const [search, setSearch] = useState('');
    const [editing, setEditing] = useState(null), [association, setAssociation] = useState(''), [busy, setBusy] = useState(false), [error, setError] = useState(null);
    async function saveAssociation(project) {
        setBusy(true); setError(null);
        try {
            await request(project.association_url, {project_id: association || null, revision: project.revision}, 'PUT');
            setEditing(null);
            router.reload({only:['projects'], preserveState:true});
        } catch (error) { setError(error.message || '关联保存失败，请重试'); }
        finally { setBusy(false); }
    }
    const groups = Object.groupBy(projects, p => String(p.owner_id));
    const selected = groups[owner];
    const terms = search.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
    const matches = (selected || []).filter(project => terms.every(term => `${project.title} ${project.code || ''} ${project.source_title || ''} ${project.project_title || ''}`.toLocaleLowerCase().includes(term)));
    function selectOwner(value) { setOwner(value); setSearch(''); }
    return <Layout title="财务工作台" eyebrow="发货管理"><Head title="财务工作台" /><div className="finance-workbench">
        <nav className="finance-crumb"><button type="button" className="ghost-button" onClick={() => selectOwner(null)}>全部业务员</button>{selected && <span>/ {selected[0].owner}</span>}</nav>
        {!selected ? <div className="finance-people">{Object.entries(groups).map(([id, list]) => <section className="surface" key={id}>
            <div className="finance-avatar">{list[0].owner[0]}</div><h2>{list[0].owner}</h2>
            <div className="finance-metrics"><span><b>{list.length}</b> 个项目</span><span><b>{number(list.reduce((a, p) => a + (p.tonnes || 0), 0))}</b> 吨</span></div>
            <button onClick={() => selectOwner(id)}>查看项目 →</button>
        </section>)}{!projects.length && <p>暂无可访问的业务项目。</p>}</div> : <section className="surface">
            <div className="section-head"><h2>{selected[0].owner}的项目</h2><span className="muted" role="status">{terms.length ? `找到 ${matches.length} / ${selected.length} 个项目` : `${selected.length} 个项目`}</span></div>
            <div className="finance-search"><label htmlFor="finance-project-search">快速查找项目</label><div className="finance-actions">
                <input id="finance-project-search" type="search" placeholder="输入项目名称或编号，支持部分文字匹配" value={search} onChange={event => setSearch(event.target.value)} />
                {search && <button type="button" className="ghost-button" onClick={() => setSearch('')}>清空搜索</button>}
            </div></div>
            <div className="finance-scroll"><table><thead><tr><th>项目</th><th>发货单</th><th>累计发货（吨）</th><th>最后发货日期</th><th>项目关联</th><th /></tr></thead><tbody>
                {matches.map(project => <tr key={project.id}><td><EditableTitle project={project} /><small>{project.code}</small>{project.source_title && project.source_title !== project.title && <small>{project.source_title}</small>}</td><td>{project.order_count}</td><td>{number(project.tonnes)}{project.pending > 0 && <small>{project.pending} 行待订正，当前为已确认吨数</small>}</td><td>{project.last_date || '—'}</td><td>{project.association_url ? editing === project.id ? <div className="finance-association" role="group" aria-label={`修改${project.title}的项目关联`}><fieldset disabled={busy}><ComboBox value={association} options={[...(canUnlink ? [{value:'',label:'暂不关联项目'}] : []), ...projectOptions]} placeholder="搜索项目、客户或编号" onChange={setAssociation} /><div className="finance-actions"><button type="button" onClick={() => saveAssociation(project)}>保存关联</button><button type="button" className="ghost-button" onClick={() => {setEditing(null);setError(null);}}>取消</button></div></fieldset>{error && <p role="alert">{error}</p>}</div> : <><span>{project.project_id ? '已关联' : '未关联'}</span><button type="button" className="ghost-button" onClick={() => {setEditing(project.id);setAssociation(project.project_id || '');setError(null);}}>修改关联</button></> : '—'}</td><td><Link href={project.url}>查看明细 →</Link></td></tr>)}
                {!matches.length && <tr><td colSpan="6">没有匹配的项目，请换个关键词或清空搜索。</td></tr>}
            </tbody></table></div>
        </section>}
        <p className="muted">台账独立统计，不修改业务资料；未建立台账的项目显示“—”。</p>
    </div></Layout>;
}

function EditableTitle({ project }) {
    const [title, setTitle] = useState(project.title), [draft, setDraft] = useState(project.title), [editing, setEditing] = useState(false), [status, setStatus] = useState(''), [error, setError] = useState('');
    const saving = useRef(false), cancelled = useRef(false), revision = useRef(project.revision ?? 0);
    useEffect(() => { setTitle(project.title); revision.current = project.revision ?? 0; }, [project.title, project.revision]);
    async function save() {
        if (saving.current || cancelled.current) return;
        const value = draft.trim();
        if (!value) { setError('名称不能为空'); return; }
        if (value === title) { setEditing(false); return; }
        saving.current = true; setStatus('保存中…'); setError('');
        try {
            const result = await request(project.rename_url, {display_title:value, revision:revision.current}, 'PUT');
            revision.current = result.revision; setTitle(result.display_title); setEditing(false); setStatus('已保存');
            router.reload({only:['projects'], preserveState:true});
        } catch (error) { setError(error.message || '名称保存失败，输入已保留'); setStatus(''); }
        finally { saving.current = false; }
    }
    if (!project.rename_url) return <strong>{title}</strong>;
    return <div className="finance-title-editor">{editing ? <input autoFocus aria-label="财务项目名称" maxLength={255} value={draft} disabled={status === '保存中…'} onChange={e => setDraft(e.target.value)} onBlur={save} onKeyDown={e => {
        if (e.key === 'Enter' && !e.nativeEvent.isComposing) { e.preventDefault(); e.currentTarget.blur(); }
        if (e.key === 'Escape') { cancelled.current = true; setEditing(false); setError(''); setStatus(''); }
    }} /> : <button type="button" className="finance-title-button" title="点击修改财务显示名称" onClick={() => {cancelled.current = false; setDraft(title); setEditing(true); setError(''); setStatus('');}}>{title}</button>}{status && <small role="status">{status}</small>}{error && <small role="alert">{error}</small>}</div>;
}
