import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../../Components/Layout';
import { number } from './ledger';
import './finance.css';

export default function Index({ projects }) {
    const [owner, setOwner] = useState(() => new URLSearchParams(window.location.search).get('owner'));
    const [search, setSearch] = useState('');
    const groups = Object.groupBy(projects, p => String(p.owner_id));
    const selected = groups[owner];
    const terms = search.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
    const matches = (selected || []).filter(project => terms.every(term => `${project.title} ${project.code}`.toLocaleLowerCase().includes(term)));
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
            <div className="finance-scroll"><table><thead><tr><th>项目</th><th>发货单</th><th>累计发货（吨）</th><th>最后发货日期</th><th /></tr></thead><tbody>
                {matches.map(project => <tr key={project.id}><td><strong>{project.title}</strong><small>{project.code}</small></td><td>{project.order_count}</td><td>{number(project.tonnes)}{project.pending > 0 && <small>{project.pending} 行待订正，当前为已确认吨数</small>}</td><td>{project.last_date || '—'}</td><td><Link href={project.url}>查看明细 →</Link></td></tr>)}
                {!matches.length && <tr><td colSpan="5">没有匹配的项目，请换个关键词或清空搜索。</td></tr>}
            </tbody></table></div>
        </section>}
        <p className="muted">台账独立统计，不修改业务资料；未建立台账的项目显示“—”。</p>
    </div></Layout>;
}
