import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { Clock3, Database, Wallet, PieChart, ArrowRight, ShieldCheck } from 'lucide-react';
import Layout from '../Components/Layout';
import '../../css/visualization.css';

const colors = ['#2878ff', '#19bfc5', '#9270ff', '#ff9c45', '#8793a8', '#e26492', '#4c9a83', '#bc9444'];
const money = (value) => value == null ? '—' : (value / 10000).toLocaleString('zh-CN', { maximumFractionDigits: 2 });
const percent = (value) => `${value.toFixed(1)}%`;

function Doughnut({ items, center, caption, available = true, label }) {
    const [active, setActive] = useState(null);
    const total = items.reduce((sum, item) => sum + (item.value ?? 0), 0);
    const valid = available && total > 0 && items.every((item) => item.value == null || item.value >= 0);
    let offset = 0;
    return <div className="viz-ring" onMouseLeave={() => setActive(null)} role="img" aria-label={`${label}：${center} ${caption}`}>
        <svg viewBox="0 0 200 200" aria-hidden="true">
            <circle cx="100" cy="100" r="76" fill="none" stroke="#eef2f7" strokeWidth="34" />
            {valid && items.map((item, index) => {
                const portion = (item.value ?? 0) / total * 100;
                const start = offset;
                offset += portion;
                return <circle key={item.name} cx="100" cy="100" r="76" fill="none" stroke={item.color || colors[index % colors.length]} className={`viz-sector ${active === index ? 'is-active' : ''}`} onMouseEnter={() => setActive(index)} onMouseLeave={() => setActive(null)} onFocus={() => setActive(index)} onBlur={() => setActive(null)} tabIndex={portion > 0 ? 0 : -1} aria-label={`${item.name} ${caption === '活跃项目' ? item.value + ' 个项目' : money(item.value) + ' 万元'} ${percent(portion)}`} strokeWidth={active === index ? 40 : 34} pathLength="100" strokeDasharray={`${portion} ${100 - portion}`} strokeDashoffset={-start} transform="rotate(-90 100 100)" />;
            })}
        </svg>
        <div className="viz-ring-center"><strong>{center}</strong><span>{caption}</span></div>
        {valid && active !== null && items[active] && <div className="viz-tooltip" role="tooltip"><strong><i style={{ background: items[active].color || colors[active % colors.length] }} />{items[active].name}</strong><span>{caption === '活跃项目' ? `${items[active].value} 个项目` : `${money(items[active].value)} 万元`}</span><b>{percent((items[active].value ?? 0) / total * 100)}</b></div>}
        {!valid && <span className="viz-ring-empty">{available && items.some((i) => i.value < 0) ? '金额异常，暂不绘制占比' : '暂无可计算数据'}</span>}
    </div>;
}

function Distribution({ people, metric, title, total }) {
    const items = people.map((p, i) => ({ name: p.name, value: p[metric], color: colors[i % colors.length] }));
    const chartable = total.value > 0 && items.every((item) => item.value == null || item.value >= 0);
    return <section className="viz-card viz-distribution"><h2>{title}</h2>
        <div className="viz-distribution-body"><Doughnut items={items} center={money(total.value)} caption="万元" available={chartable} label={title} />
            <ul className="viz-legend">{items.map((item) => <li key={item.name}><i style={{ background: item.color }} /><span title={item.name}>{item.name}</span><b>{chartable && item.value != null ? percent(item.value / total.value * 100) : '—'}</b><small>{money(item.value)} 万元</small></li>)}</ul>
        </div><p className="viz-footnote">覆盖 {total.coverage}{total.abnormal > 0 && ` · ${total.abnormal} 项负金额记录，按业务员汇总净额展示`}</p>
    </section>;
}

export default function Visualization({ visualization }) {
    const { scope, as_of, collection, projects, details_url } = visualization;
    const barMetrics = [['occurred', '已发生', '#2878ff'], ['paid', '已回款', '#19bfc5'], ['unpaid', '欠款', '#ffb83f']];
    const maximum = Math.max(1, ...(projects?.top_unpaid || []).flatMap((p) => barMetrics.map(([key]) => Math.max(0, p[key] ?? 0))));
    return <Layout title="可视化大盘" hideHeader>
        <Head title="可视化大盘" />
        <div className="visualization-page">
            <header className="viz-header"><div><p>经营分析</p><h1>可视化大盘</h1><span>回款进度 · 业务员金额分布 · 项目阶段</span></div>
                <div className="viz-header-actions"><div><b><ShieldCheck size={14} />{scope}</b><span>截至 {as_of}</span></div>{details_url && <Link href={details_url}>查看业务明细 <ArrowRight size={16} /></Link>}</div>
            </header>
            {!collection && !projects && <section className="viz-card viz-empty">暂无可查看的数据，请联系管理员确认来源对象权限。</section>}
            <div className="viz-top-grid">
                {collection && <section className="viz-card viz-collection"><h2>公司回款情况</h2><div className="viz-collection-body">
                    <Doughnut items={[{ name: '已回款', value: collection.paid, color: '#19bfc5' }, { name: '尚未回款', value: collection.remaining, color: '#ffbf4b' }]} center={collection.ratio == null ? '—' : percent(collection.ratio)} caption="回款比例" available={collection.chartable} label="公司回款情况" />
                    <div className="viz-collection-values"><p><i style={{ background: '#19bfc5' }} />已回款<strong>{money(collection.paid)} <small>万元</small></strong></p><p><i style={{ background: '#ffb83f' }} />尚未回款<strong>{money(collection.remaining)} <small>万元</small></strong></p><span>同源发生额 {money(collection.occurred)} 万元 · 覆盖 {collection.coverage}</span>{collection.ratio != null && !collection.chartable && <span className="viz-warning">金额异常，保留实际比例</span>}</div>
                </div><p className="viz-footnote">项目主档口径 · 与经营大盘总回款比例一致</p></section>}
                {projects && <section className="viz-card viz-totals"><div className="viz-card-heading"><h2>项目主档金额汇总</h2><span>业务员图表采用项目主档口径</span></div><div className="viz-metric-grid">{barMetrics.map(([key, name], index) => {
                    const Icon = [Database, Wallet, PieChart][index];
                    return <article key={key} className={`viz-metric viz-metric-${key}`}><div><Icon size={23} /><span>{name}金额</span></div><strong>{money(projects.totals[key].value)} <small>万元</small></strong><p>覆盖 {projects.totals[key].coverage}</p></article>;
                })}</div></section>}
            </div>
            {projects && <><div className="viz-distribution-grid">
                <Distribution people={projects.salespeople} metric="occurred" title="业务员已发生金额占比" total={projects.totals.occurred} />
                <Distribution people={projects.salespeople} metric="unpaid" title="业务员欠款金额占比" total={projects.totals.unpaid} />
                <Distribution people={projects.salespeople} metric="paid" title="业务员已回款金额占比" total={projects.totals.paid} />
            </div><div className="viz-bottom-grid">
                <section className="viz-card viz-project-bars"><div className="viz-card-heading"><h2>欠款项目回款情况</h2><div className="viz-bar-legend">{barMetrics.map(([key, name, color]) => <span key={key}><i style={{ background: color }} />{name}</span>)}</div></div><p className="viz-subtitle">欠款金额前五 · 万元</p>
                    {projects.top_unpaid.length ? <div className="viz-bars">{projects.top_unpaid.map((project) => <div className="viz-bar-row" key={project.id}><Link title={`${project.code} · ${project.name}`} href={`${details_url}?record=${project.id}&mode=detail`}>{project.name}<small>{project.code}</small></Link><div>{barMetrics.map(([key, name, color]) => <div className="viz-bar-track" key={key} aria-label={`${project.name} ${name} ${money(project[key])} 万元`}><span style={{ width: `${Math.max(0, project[key] ?? 0) / maximum * 76}%`, background: color }} /><b>{money(project[key])}</b></div>)}</div></div>)}</div> : <p className="viz-empty">暂无有效欠款项目</p>}
                    <p className="viz-footnote">默认显示前五个欠款项目 · 缺失金额显示 —</p>
                </section>
                <section className="viz-card viz-aging"><h2>项目未回款时间排行</h2><p className="viz-subtitle">按未回款时长降序</p><div className="viz-aging-scroll"><table><thead><tr><th>项目</th><th>未回款时长</th><th>欠款（万元）</th></tr></thead><tbody>{projects.aging.map((project, index) => <tr key={project.id} className={project.days == null ? '' : `viz-age-${Math.min(index, 3)}`}><td><Link title={`${project.code} · ${project.name}`} href={`${details_url}?record=${project.id}&mode=detail`}>{project.name}</Link></td><td><Clock3 size={15} /><b>{project.days == null ? '日期未维护' : `${project.days} 天`}</b></td><td>{money(project.unpaid)}</td></tr>)}</tbody></table></div>{!projects.aging.length && <p className="viz-empty">暂无可排行的欠款项目</p>}<p className="viz-footnote">时间越久，提示越醒目。{projects.aging_basis}{projects.aging_excluded > 0 && ` · ${projects.aging_excluded} 个欠款项目因日期缺失或无效未纳入`}</p></section>
                <section className="viz-card viz-stages"><h2>项目阶段分布</h2><div className="viz-stage-body"><Doughnut items={projects.stages} center={projects.active_count} caption="活跃项目" label="项目阶段分布" /><ul className="viz-legend">{projects.stages.map((stage, index) => <li key={stage.name}><i style={{ background: colors[index % colors.length] }} /><span>{stage.name}</span><b>{stage.value}</b><small>{percent(stage.value / projects.active_count * 100)}</small></li>)}</ul></div><div className="viz-statuses">{projects.inactive.map((status) => <span key={status.name}>{status.name}<b>{status.value}</b></span>)}</div><p className="viz-footnote">阶段分布按当前项目总体状态统计</p></section>
            </div></>}
            <footer className="viz-footer">实时读取现有记录 · 金额单位：万元 · <Link href="/">返回经营大盘</Link></footer>
        </div>
    </Layout>;
}
