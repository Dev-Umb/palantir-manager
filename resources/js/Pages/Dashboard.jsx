import { Head, Link, usePoll } from '@inertiajs/react';
import { ArrowRight, Bell, Clock3, ShieldCheck } from 'lucide-react';
import {
    CashFlowPanel,
    CockpitEmpty,
    CockpitKpis,
    ProjectAmountPanel,
    ProductionDeliveryPanel,
    ProjectProgressPanel,
    ProjectStatusPanel,
    TenderPipelinePanel,
} from '../Components/CompanyOperationsCockpit';
import Layout from '../Components/Layout';

export default function Dashboard({ cockpit = {}, recentProjects = [], notificationRisks = [] }) {
    usePoll(15000, { only: ['cockpit'] }, { mode: 'rest' });

    const meta = cockpit.meta || {};
    const kpis = cockpit.kpis || [];
    const panels = cockpit.panels || {};
    const hasCockpitData = kpis.length > 0 || Object.keys(panels).length > 0;

    return (
        <Layout title="公司经营驾驶舱" eyebrow="经营大盘" hideHeader>
            <Head title="公司经营驾驶舱" />
            <header className="cockpit-page-head">
                <div><p>经营大盘</p><h1>公司经营驾驶舱</h1></div>
                <div className="cockpit-page-meta">
                    <span className="scope"><ShieldCheck size={14} aria-hidden="true" />{meta.scope || '我的可见范围'}</span>
                    <span><Clock3 size={14} aria-hidden="true" />截至 {formatAsOf(meta.as_of)}</span>
                </div>
            </header>

            <CockpitKpis kpis={kpis} />
            <ProjectAmountPanel panel={panels.project_amounts} />
            <RiskPanel risks={notificationRisks} />

            {hasCockpitData ? (
                <div className="cockpit-chart-grid">
                    <CashFlowPanel panel={panels.cash_flow} />
                    <TenderPipelinePanel panel={panels.tender_pipeline} />
                    <ProjectStatusPanel panel={panels.project_status} />
                    <ProductionDeliveryPanel panel={panels.production_delivery} />
                </div>
            ) : <CockpitEmpty />}

            <ProjectProgressPanel progress={cockpit.project_progress} projects={cockpit.project_progresses} />
            <RecentProjects projects={recentProjects} />
        </Layout>
    );
}

function RiskPanel({ risks }) {
    return (
        <section className="surface cockpit-risk-panel" aria-labelledby="cockpit-risks-title">
            <div className="cockpit-panel-head">
                <div><p>项目风险</p><h2 id="cockpit-risks-title">合同与回款提醒</h2></div>
                <Link href="/notifications">通知中心 <ArrowRight size={14} aria-hidden="true" /></Link>
            </div>
            {risks.length ? (
                <div className="notification-risk-grid">
                    {risks.map((risk) => {
                        const content = (
                            <>
                                <Bell size={17} aria-hidden="true" />
                                <div><strong>{risk.project_no} · {risk.project_name}</strong><span>{risk.type_label}</span></div>
                                {!risk.read && <b>未读</b>}
                            </>
                        );

                        return risk.project_url
                            ? <Link href={risk.project_url} className={`notification-risk-card ${risk.type}`} key={risk.id}>{content}</Link>
                            : <div className={`notification-risk-card ${risk.type}`} key={risk.id}>{content}</div>;
                    })}
                </div>
            ) : <p className="muted cockpit-risk-empty">当前没有到期提醒。</p>}
        </section>
    );
}

function RecentProjects({ projects }) {
    return (
        <section className="surface cockpit-recent-projects">
            <div className="cockpit-panel-head">
                <div><p>项目</p><h2>最近项目</h2></div>
                <Link href="/objects/project">进入项目表 <ArrowRight size={14} aria-hidden="true" /></Link>
            </div>
            {projects.length ? (
                <div className="table-scroll">
                    <table className="data-table">
                        <thead><tr><th>项目编号</th><th>项目名称</th><th>负责业务员</th><th>总体状态</th><th>合同状态</th><th>回款状态</th></tr></thead>
                        <tbody>
                            {projects.map((record) => (
                                <tr key={record.id}>
                                    <td className="mono">{record.payload.project_no || record.code}</td>
                                    <td>{record.title}</td>
                                    <td>{record.display?.business_owner_user_id || '未分配'}</td>
                                    <td><span className="pill">{record.payload.overall_status || '状态未维护'}</span></td>
                                    <td>{record.payload.contract_status || '未签署'}</td>
                                    <td>{record.payload.payment_status || '未回款'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : <p className="muted">当前可见范围内暂无项目。</p>}
        </section>
    );
}

function formatAsOf(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';

    return new Intl.DateTimeFormat('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(date).replaceAll('/', '-');
}

function formatAmount(value) {
    return new Intl.NumberFormat('zh-CN', { maximumFractionDigits: 2 }).format(value || 0);
}
