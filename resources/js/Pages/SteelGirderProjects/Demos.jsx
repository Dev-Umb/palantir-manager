import { useRef, useState } from 'react';
import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ArrowUpRight, Box, Check, ChevronRight, Clock3, Copy, Globe2, History, Plus, Search, UploadCloud } from 'lucide-react';
import Layout from '../../Components/Layout';
import SteelGirderNavigation from '../../Components/SteelGirderNavigation';
import './demos.css';

const root = '/steel-girder-projects/demos';
const labels = { ready: '已就绪', failed: '失败', publishing: '发布中', withdrawing: '撤下中', withdrawn: '已撤下' };
const time = value => value ? value.replace('T', ' ').slice(0, 16) : '未记录';

function Badge({ state, live }) {
    return <span className={'ds-badge ds-' + (live ? 'live' : state)}><i />{live ? '线上版本' : labels[state] || '暂无发布'}</span>;
}

function PackageForm({ site, close }) {
    const [filename, setFilename] = useState('');
    const picker = useRef(null);
    return <section className="ds-upload">
        <h2>{site ? '上传新版本' : '新建演示站点'}</h2>
        <Form action={root} method="post" encType="multipart/form-data" onSuccess={close}>
            {({ processing, progress, errors }) => <>
                {site ? <input type="hidden" name="site_id" value={site.id} /> : <label className="ds-field">站点名称<input name="name" required maxLength={180} placeholder="例如：吊车吊装演示" /></label>}
                <label className="ds-drop"><UploadCloud size={25} /><strong>{filename || '选择 HTML 离线包'}</strong><small>ZIP · 最大 200 MiB</small><input ref={picker} type="file" accept=".zip" name="package" required onChange={e => setFilename(e.target.files[0]?.name || '')} /></label>
                {Object.values(errors).map(msg => <p role="alert" key={msg}>{msg}</p>)}
                <div className="ds-actions"><button type="button" disabled={processing} onClick={close}>取消</button><button className="ds-primary" disabled={processing}>{processing ? (progress?.percentage < 100 ? '上传 ' + progress.percentage + '%' : '发布中…') : '上传并发布'}</button></div>
            </>}
        </Form>
    </section>;
}

export default function Demos({ sites = [], site, deployments = [], events = {}, share, errors = {} }) {
    const [upload, setUpload] = useState(false);
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState('');
    const [feedback, setFeedback] = useState('');
    async function copy() {
        try { await navigator.clipboard.writeText(share); setFeedback('已复制站点链接'); }
        catch { setFeedback('请选中站点链接复制'); }
    }
    const live = deployments.find(d => d.id === site?.current_id);
    const matches = sites.filter(s => s.name.toLowerCase().includes(query.toLowerCase()));
    return <Layout hideHeader><Head title={(site?.name || '演示站点') + ' · 钢箱梁工作台'} /><SteelGirderNavigation />
        <main className="demo-studio"><header className="ds-header">
            {site && <Link className="ds-back" href={root}><ArrowLeft size={15} />全部站点</Link>}
            <div className="ds-heading"><div className="ds-title"><span className="ds-icon"><Box /></span><h1>{site?.name || '演示站点'}</h1></div>
                <button className="ds-primary" onClick={() => setUpload(true)}><Plus size={16} />{site ? '上传新版本' : '新建站点'}</button></div>
        </header>
        {upload && <PackageForm site={site} close={() => setUpload(false)} />}
        {site ? <>
            <section className="ds-current"><div><div className="ds-caption"><Globe2 size={15} />线上部署</div><h2>{live ? '版本 v' + live.version : '尚无线上的版本'}</h2>
                {live && <p>{live.name} · {time(live.updated_at)}</p>}
                <div className="ds-share">{share}</div></div>
                <div className="ds-actions"><button onClick={copy}><Copy size={14} />复制链接</button>{live && <a className="ds-primary" href={share} target="_blank" rel="noreferrer">打开站点 <ArrowUpRight size={15} /></a>}</div></section>
            {feedback && <p role="status">{feedback}</p>}
            {errors.withdraw && <p role="alert">{errors.withdraw}</p>}
            <div className="ds-toolbar"><h2><History size={17} />部署历史 <small>{deployments.length}</small></h2>
                <select aria-label="筛选部署状态" value={filter} onChange={e => setFilter(e.target.value)}><option value="">全部状态</option>{Object.entries(labels).map(([k,v]) => <option key={k} value={k}>{v}</option>)}</select></div>
            <div className="ds-list">{deployments.filter(d => !filter || filter === d.status).map(d => <article key={d.id} className="ds-version">
                <div className="ds-row"><span className="ds-ver">v{d.version}</span><div className="ds-grow"><strong>{d.name}</strong><small>{d.operator || '未记录'} · {time(d.created_at)}</small></div><Badge state={d.status} live={live?.id === d.id} />
                {d.status === 'ready' && <a className="ds-preview" href={d.url} target="_blank" rel="noreferrer">预览 <ArrowUpRight size={14} /></a>}</div>
                <details><summary>版本详情与操作</summary><div className="ds-details">{d.failure && <p role="alert">{d.failure}</p>}
                {(events[d.id] || []).map(e => <p key={e.id}><Clock3 size={13} /> {time(e.created_at)} · {e.action} · {e.operator || '未记录'}</p>)}
                <div className="ds-actions">
                {d.status === 'ready' && live?.id !== d.id && <button onClick={() => window.confirm('将站点切换到 v' + d.version + '？') && router.post(root + '/' + d.id + '/promote')}><Check size={14} />设为线上版本</button>}
                {['ready','withdrawing'].includes(d.status) && <button onClick={() => (d.status === 'withdrawing' || window.confirm(live?.id === d.id ? '撤下当前版本后，站点链接将暂停访问。确认撤下？' : '确认撤下此版本的公开链接？')) && router.delete(root + '/' + d.id)}>{d.status === 'withdrawing' ? '检查撤下状态' : '撤下版本'}</button>}
                {d.status === 'failed' && <button onClick={() => setUpload(true)}>重新上传 ZIP</button>}</div></div></details>
            </article>)}{!deployments.some(d => !filter || filter === d.status) && <p className="ds-empty">暂无符合条件的版本</p>}</div>
        </> : <><div className="ds-toolbar"><h2>全部站点 <small>{sites.length}</small></h2><label className="ds-search"><Search size={15} /><input aria-label="搜索站点" placeholder="搜索站点…" value={query} onChange={e => setQuery(e.target.value)} /></label></div>
        <div className="ds-list">{matches.map(s => <Link className="ds-row ds-site" key={s.id} href={root + '/' + s.id}><span className="ds-tile"><Globe2 size={21} /></span><div className="ds-grow"><strong>{s.name}</strong><small>{s.sequence} 次部署 · {time(s.updated_at)}</small></div>{s.live_version && <span className="ds-ver">v{s.live_version}</span>}<Badge state={s.latest_status} live={s.latest_status === 'ready' && !!s.current_id} /><ChevronRight size={15} /></Link>)}
        {!matches.length && <div className="ds-empty"><Box size={26} /><h2>{query ? '没有匹配的站点' : '还没有演示站点'}</h2><p>{query ? '试试其他站点名称。' : '新建站点并上传 ZIP，即可生成分享链接。'}</p></div>}</div></>}
        </main></Layout>;
}
