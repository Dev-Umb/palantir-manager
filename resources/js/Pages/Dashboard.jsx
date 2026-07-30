import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Bell, ClipboardPlus, FileWarning, Network, WalletCards } from 'lucide-react';
import { useState } from 'react';
import ComboBox from '../Components/ComboBox';
import Layout from '../Components/Layout';

export default function Dashboard({ stats, boards, projectFlows, recentProjects, stockRisks, notificationSummary = {}, notificationRisks = [] }) {
    const { auth } = usePage().props;
    const canRequest = auth.permissions.includes('requisition.create');
    const projectAccess = auth.permissions.some((key) => key.startsWith('object.project.'));
    const [projectId, setProjectId] = useState(projectFlows[0]?.id || '');
    const selectedProject = projectFlows.find((project) => project.id === projectId) || projectFlows[0];

    return (
        <Layout
            title="经营大盘"
            eyebrow="经营大盘"
        >
            <Head title="经营大盘" />
            <div className="kpi-grid">
                {stats.map((item) => (
                    <article key={item.label} className="metric">
                        <span>{item.label}</span>
                        <strong>{item.value}<small>{item.unit}</small></strong>
                    </article>
                ))}
            </div>

            <section className="surface notification-risk-panel">
                <div className="section-head">
                    <div>
                        <p>项目风险</p>
                        <h2>合同与回款提醒</h2>
                        <span>合同 {notificationSummary.contract || 0} 项 · 回款 {notificationSummary.payment || 0} 项</span>
                    </div>
                    <Link className="small-action" href="/notifications"><Bell size={15} /> 通知中心</Link>
                </div>
                {notificationRisks.length ? (
                    <div className="notification-risk-grid">
                        {notificationRisks.slice(0, 8).map((risk) => {
                            const Icon = risk.type === 'contract' ? FileWarning : WalletCards;
                            const body = (
                                <>
                                    <Icon size={18} />
                                    <div>
                                        <strong>{risk.project_no} · {risk.project_name}</strong>
                                        <span>{risk.message}</span>
                                    </div>
                                    {!risk.read && <b>未读</b>}
                                </>
                            );

                            return risk.project_url
                                ? <Link className={`notification-risk-card ${risk.type}`} href={risk.project_url} key={risk.id}>{body}</Link>
                                : <div className={`notification-risk-card ${risk.type}`} key={risk.id}>{body}</div>;
                        })}
                    </div>
                ) : <p className="muted">暂无超过三个月的合同或回款风险。</p>}
            </section>

            <div className="board-grid">
                {boards.map((board) => (
                    <section key={board.title} className={`surface dashboard-board ${board.type === 'flow' ? 'wide-board' : ''}`}>
                    <div className="section-head">
                        <div>
                                <p>{board.title === '经营大盘' ? '项目流转' : '业务情况'}</p>
                                <h2>{board.title === '经营大盘' ? '项目流转' : board.title}</h2>
                                <span>{board.desc}</span>
                        </div>
                            {board.title === '经营大盘' && <Network size={20} />}
                            {board.title === '采购大盘' && canRequest && <Link className="small-action" href="/requests/create"><ClipboardPlus size={15} /> 提申请</Link>}
                    </div>
                        {board.type === 'flow' ? (
                            <>
                                <div className="project-flow-select">
                                    <label>
                                        <span>选择项目</span>
                                        <ComboBox value={projectId} onChange={setProjectId} options={projectFlows.map((project) => ({ value: project.id, label: project.label }))} />
                                    </label>
                                    {selectedProject && (
                                        <div className="project-flow-summary">
                                            <strong>当前走到：{selectedProject.current_step}</strong>
                                            <span>累计发货重量：{selectedProject.shipped_weight_ton || 0} 吨</span>
                                        </div>
                                    )}
                                </div>
                                <div className="flow-row">
                                    {(selectedProject?.steps || board.items).map((item) => (
                                        <div key={item.label} className={`flow-node ${item.status || ''}`}>
                                            <span>{item.label}</span>
                                            <strong>{item.value ?? stepText(item.status)}{item.unit && <small>{item.unit}</small>}</strong>
                                        </div>
                                    ))}
                                </div>
                            </>
                        ) : (
                            <div className="board-metrics">
                                {board.items.map((item) => (
                                    <div key={item.label}>
                                        <span>{item.label}</span>
                                        <strong>{item.value}<small>{item.unit}</small></strong>
                                    </div>
                                ))}
                            </div>
                        )}
                        {board.title === '库存大盘' && (
                            <div className="risk-list">
                                <h3>库存提醒</h3>
                                {stockRisks.length ? stockRisks.map((record) => {
                                    const href = canRequest && record.payload.material_id
                                        ? `/requests/create?material_id=${record.payload.material_id}&requester=库管&reason=${encodeURIComponent('库存低于预警，需补料')}`
                                        : null;
                                    const content = (
                                        <>
                                            <div>
                                                <span>{record.display?.material_id || record.title || record.code}</span>
                                                <small>最低库存 {record.payload.minimum_stock || '-'}</small>
                                            </div>
                                            <strong>{record.payload.balance_weight ?? '-'} kg</strong>
                                            {href && <span className="small-action">提申请</span>}
                                        </>
                                    );

                                    return href
                                        ? <Link className="risk-row" href={href} key={record.id}>{content}</Link>
                                        : <div className="risk-row" key={record.id}>{content}</div>;
                                }) : <p className="muted">暂无库存风险。</p>}
                            </div>
                        )}
                    </section>
                ))}
                    </div>

            <section className="surface">
                <div className="section-head">
                    <div>
                        <p>项目</p>
                        <h2>最近项目</h2>
                    </div>
                    {projectAccess && <Link className="small-action" href="/objects/project">看项目 <ArrowRight size={15} /></Link>}
                </div>
                <div className="table-scroll">
                    <table className="data-table">
                        <thead><tr><th>编号</th><th>项目</th><th>阶段</th><th>责任岗位</th><th>风险</th></tr></thead>
                        <tbody>
                            {recentProjects.map((record) => (
                                <tr key={record.id}>
                                    <td className="mono">{record.code}</td>
                                    <td>{record.title}</td>
                                    <td><span className="pill">{record.payload.stage}</span></td>
                                    <td>{record.payload.owner_role}</td>
                                    <td>{record.payload.risk}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </Layout>
    );
}

function stepText(status) {
    if (status === 'done') return '已过';
    if (status === 'current') return '当前';
    if (status === 'parallel-shipment' || status === 'parallel-payment') return '进行中';
    return '未到';
}
