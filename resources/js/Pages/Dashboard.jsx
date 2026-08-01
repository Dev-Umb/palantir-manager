import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Bell } from 'lucide-react';
import Layout from '../Components/Layout';

const statuses = ['投标中', '已中标', '已拿到加工函', '合同签署', '已完成'];

export default function Dashboard({ stats = [], statusSummary = {}, recentProjects = [], notificationRisks = [] }) {
    return (
        <Layout title="业务与合同总览" eyebrow="经营总览">
            <Head title="业务与合同总览" />
            <div className="kpi-grid">
                {stats.map((item) => <article key={item.label} className="metric"><span>{item.label}</span><strong>{item.value}<small>{item.unit}</small></strong></article>)}
            </div>
            <section className="surface dashboard-board">
                <div className="section-head"><div><p>项目状态</p><h2>业务推进情况</h2><span>状态由业务人员人工维护，合同签署状态与合同表联动。</span></div><Link className="small-action" href="/objects/project">进入项目表 <ArrowRight size={15} /></Link></div>
                <div className="board-metrics">{statuses.map((status) => <div key={status}><span>{status}</span><strong>{statusSummary[status] || 0}<small>个</small></strong></div>)}</div>
            </section>
            <section className="surface notification-risk-panel">
                <div className="section-head"><div><p>业务提醒</p><h2>当前报警项目</h2></div><Link className="small-action" href="/notifications"><Bell size={15} /> 通知中心</Link></div>
                {notificationRisks.length ? <div className="notification-risk-grid">{notificationRisks.map((risk) => <Link href={risk.project_url} className={`notification-risk-card ${risk.type}`} key={risk.id}><Bell size={17} /><div><strong>{risk.project_no} · {risk.project_name}</strong><span>{risk.type_label}</span></div>{!risk.read && <b>未读</b>}</Link>)}</div> : <p className="muted">当前没有到期提醒。</p>}
            </section>
            <section className="surface">
                <div className="section-head"><div><p>项目</p><h2>最近项目</h2></div><Link className="small-action" href="/objects/contract">合同表 <ArrowRight size={15} /></Link></div>
                <div className="table-scroll"><table className="data-table"><thead><tr><th>项目编号</th><th>项目名称</th><th>负责业务员</th><th>总体状态</th><th>合同状态</th><th>回款状态</th></tr></thead><tbody>{recentProjects.map((record) => <tr key={record.id}><td className="mono">{record.payload.project_no || record.code}</td><td>{record.title}</td><td>{record.display?.business_owner_user_id || '未分配'}</td><td><span className="pill">{record.payload.overall_status || '投标中'}</span></td><td>{record.payload.contract_status || '未签署'}</td><td>{record.payload.payment_status || '未回款'}</td></tr>)}</tbody></table></div>
            </section>
        </Layout>
    );
}
