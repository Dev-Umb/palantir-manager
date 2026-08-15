import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, CircleDollarSign, Factory, Gauge, Target } from 'lucide-react';
import { useState } from 'react';
import {
    CartesianGrid,
    Cell,
    Line,
    LineChart,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

const kpiIcons = {
    occurred_amount: Factory,
    collection_rate: Gauge,
    tender_win_rate: Target,
    current_debt: CircleDollarSign,
};

const chartColors = ['var(--steel)', 'var(--mint)', 'var(--chart-violet)', 'var(--chart-orange)'];
const workOrderColors = ['var(--muted)', 'var(--steel)', 'var(--red)', 'var(--mint)'];

export function CockpitKpis({ kpis = [] }) {
    if (!kpis.length) return null;

    return (
        <div className="cockpit-kpi-grid" aria-label="公司经营关键指标">
            {kpis.map((kpi) => {
                const Icon = kpiIcons[kpi.key] || Gauge;
                const formatted = formatKpi(kpi);

                return (
                    <article className={`cockpit-kpi ${kpi.key}`} key={kpi.key}>
                        <div className="cockpit-kpi-label"><span>{kpi.label}</span><Icon size={17} aria-hidden="true" /></div>
                        <strong>{formatted.value}<small>{formatted.unit}</small></strong>
                        <div className="cockpit-kpi-context">
                            <span>{kpi.hint}</span>
                            <span>{coverageText(kpi.coverage)}</span>
                        </div>
                    </article>
                );
            })}
        </div>
    );
}

export function ProjectAmountPanel({ panel }) {
    if (!panel) return null;

    return (
        <section className="surface cockpit-project-amounts" aria-labelledby="project-amounts-title">
            <PanelHeader
                eyebrow="项目主档金额"
                title="公司与业务员金额汇总"
                id="project-amounts-title"
                href={panel.url}
                action="查看项目主表"
            />
            <div className="cockpit-project-amount-cards" aria-label="公司项目金额总计">
                {panel.company.map((amount) => (
                    <article key={amount.key}>
                        <span>{amount.label}</span>
                        <strong>{formatAmount(amount.value)}</strong>
                        <small>{coverageText(amount.coverage)}</small>
                    </article>
                ))}
            </div>
            <div className="table-scroll">
                <table className="data-table cockpit-salesperson-amounts">
                    <thead>
                        <tr><th>业务员</th><th>项目记录</th><th>已发生金额总计</th><th>已回款金额总计</th><th>未回款金额总计</th></tr>
                    </thead>
                    <tbody>
                        {panel.salespeople.map((salesperson) => (
                            <tr key={salesperson.user_id}>
                                <td><strong>{salesperson.name}</strong></td>
                                <td>{salesperson.projects_count} 个</td>
                                {salesperson.amounts.map((amount) => (
                                    <td key={amount.key}>
                                        <strong>{formatAmount(amount.value)}</strong>
                                        <small>{coverageText(amount.coverage)}</small>
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
                {!panel.salespeople.length && <p className="muted cockpit-project-amount-empty">暂无已分配给现有账号的项目记录。</p>}
            </div>
            <p className="cockpit-panel-foot">
                共 {panel.projects_count} 条项目记录
                {panel.unassigned_projects_count > 0 && ` · ${panel.unassigned_projects_count} 条未分配或账号无效，仅计入公司总计`}
                {' · '}金额空值不参与合计 · 每 15 秒自动刷新 · 更新于 {formatPanelAsOf(panel.as_of)}
            </p>
        </section>
    );
}

export function CashFlowPanel({ panel }) {
    if (!panel) return null;

    const numericValues = panel.series.map((item) => item.value).filter((value) => Number.isFinite(value));
    const maximum = Math.max(...numericValues, 0);

    return (
        <article className="surface cockpit-chart-card" aria-labelledby="cash-flow-title">
            <PanelHeader eyebrow="财务快照" title="合同到现金转化" id="cash-flow-title" href={panel.url} action="查看明细" />
            <p className="cockpit-chart-note">同一金额刻度展示当前累计事实，不代表历史趋势。</p>
            <div className="cockpit-bar-chart">
                {panel.series.map((item) => (
                    <div className="cockpit-bar-row" key={item.key}>
                        <span>{item.label}</span>
                        <span className="cockpit-bar-track" aria-hidden="true">
                            <span className={`cockpit-bar-fill ${item.key}`} style={{ width: `${barWidth(item.value, maximum)}%` }} />
                        </span>
                        <strong>{formatWan(item.value)}</strong>
                    </div>
                ))}
            </div>
            <p className="cockpit-panel-foot">{panel.series.map((item) => `${item.label} ${coverageText(item.coverage)}`).join(' · ')}</p>
        </article>
    );
}

export function TenderPipelinePanel({ panel }) {
    if (!panel) return null;

    const maximum = Math.max(...panel.statuses.map((item) => item.count), 0);

    return (
        <article className="surface cockpit-chart-card" aria-labelledby="tender-pipeline-title">
            <PanelHeader eyebrow="招投标" title="当前招投标管线" id="tender-pipeline-title" href={panel.url} action={`${panel.records_count} 条记录`} />
            <p className="cockpit-chart-note">按当前状态分布；不是历史阶段转化漏斗。</p>
            <div className="cockpit-bar-chart tender">
                {panel.statuses.map((item) => (
                    <div className="cockpit-bar-row" key={item.status}>
                        <span>{item.status}</span>
                        <span className="cockpit-bar-track" aria-hidden="true">
                            <span className={`cockpit-bar-fill ${tenderTone(item.status)}`} style={{ width: `${barWidth(item.count, maximum)}%` }} />
                        </span>
                        <strong>{item.count}</strong>
                    </div>
                ))}
            </div>
            <p className="cockpit-panel-foot">
                预算金额{panel.budget_total === null ? '暂无有效数据' : ` ${formatWan(panel.budget_total)} 万元`}
                {' · '}{coverageText(panel.budget_coverage)} · 中标率分母：已递交 + 已中标 + 未中标
            </p>
        </article>
    );
}

export function ProjectStatusPanel({ panel }) {
    if (!panel) return null;

    const slices = panel.statuses.filter((item) => item.count > 0);
    const accessibleSummary = panel.statuses.map((item) => `${item.status}${item.count}个`).join('，');

    return (
        <article className="surface cockpit-chart-card" aria-labelledby="project-status-title">
            <PanelHeader eyebrow="项目履约" title="活跃项目状态分布" id="project-status-title" href={panel.url} action={`${panel.records_count} 个项目`} />
            <p className="cockpit-chart-note">活跃仅包含投标中、已中标、已拿到加工函和合同签署。</p>
            {panel.active_total > 0 ? (
                <div className="cockpit-donut-layout">
                    <div className="cockpit-donut" role="img" aria-label={`活跃项目共${panel.active_total}个：${accessibleSummary}`}>
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie data={slices} dataKey="count" nameKey="status" innerRadius="62%" outerRadius="88%" paddingAngle={2} stroke="none" isAnimationActive={false}>
                                    {slices.map((item) => (
                                        <Cell key={item.status} fill={chartColors[panel.statuses.findIndex((status) => status.status === item.status) % chartColors.length]} />
                                    ))}
                                </Pie>
                                <Tooltip formatter={(value, name) => [`${value} 个`, name]} />
                            </PieChart>
                        </ResponsiveContainer>
                        <div className="cockpit-donut-center"><strong>{panel.active_total}</strong><span>活跃项目</span></div>
                    </div>
                    <div className="cockpit-chart-legend" aria-label="活跃项目状态图例">
                        {panel.statuses.map((item, index) => (
                            <div key={item.status}>
                                <span><i style={{ background: chartColors[index % chartColors.length] }} />{item.status}</span>
                                <strong>{item.count} · {item.percentage === null ? '—' : `${item.percentage}%`}</strong>
                            </div>
                        ))}
                    </div>
                </div>
            ) : <ChartEmpty text="暂无活跃项目数据" />}
            <p className="cockpit-panel-foot">非活跃另列：已完成 {panel.completed_count} · 状态未维护 {panel.unmaintained_count}</p>
        </article>
    );
}

export function ProductionDeliveryPanel({ panel }) {
    if (!panel) return null;

    const shipment = panel.shipment;
    const production = panel.production;

    return (
        <article className="surface cockpit-chart-card" aria-labelledby="production-delivery-title">
            <PanelHeader
                eyebrow="生产交付"
                title="生产与发货快照"
                id="production-delivery-title"
                href={shipment?.url || production?.url}
                action={production ? coverageText(production.coverage) : '查看发货'}
            />
            <div className="cockpit-production-stats">
                {production && (
                    <div><span>生产量</span><strong>{formatTon(production.total_ton)}</strong><small>计划重量 {formatTon(production.planned_ton)}</small></div>
                )}
                {shipment && (
                    <div><span>累计发货量</span><strong>{formatTon(shipment.total_ton)}</strong><small>按有效发货记录汇总</small></div>
                )}
            </div>
            {shipment && (
                <>
                    <div className="cockpit-mini-chart-head"><strong>月度发货吨位</strong><span>单位：吨</span></div>
                    {shipment.monthly.length ? (
                        <div className="cockpit-line-chart" role="img" aria-label={`月度发货吨位：${shipment.monthly.map((item) => `${item.label}${item.ton}吨`).join('，')}`}>
                            <ResponsiveContainer width="100%" height="100%">
                                <LineChart data={shipment.monthly} margin={{ top: 10, right: 12, left: -12, bottom: 0 }}>
                                    <CartesianGrid stroke="var(--line)" strokeDasharray="3 3" vertical={false} />
                                    <XAxis dataKey="label" tick={{ fill: 'var(--muted)', fontSize: 11 }} tickLine={false} axisLine={false} minTickGap={18} />
                                    <YAxis tick={{ fill: 'var(--muted)', fontSize: 11 }} tickLine={false} axisLine={false} width={42} />
                                    <Tooltip formatter={(value) => [`${formatNumber(value)} 吨`, '发货量']} />
                                    <Line type="monotone" dataKey="ton" stroke="var(--steel)" strokeWidth={3} dot={{ r: 4, fill: 'var(--surface)', strokeWidth: 2 }} activeDot={{ r: 5 }} isAnimationActive={false} />
                                </LineChart>
                            </ResponsiveContainer>
                        </div>
                    ) : <ChartEmpty text="暂无带有效发货日期的吨位记录" />}
                    <p className="cockpit-chart-note shipment-note">
                        按 ship_date 月汇总有效吨位 · 趋势{coverageText(shipment.trend_coverage)}
                        {shipment.undated_ton > 0 ? ` · ${formatNumber(shipment.undated_ton)} 吨缺日期只计累计值` : ''}
                        {shipment.invalid_quantity_count > 0 ? ` · ${shipment.invalid_quantity_count} 条吨位异常未计入` : ''}
                    </p>
                </>
            )}
            {production && <WorkOrderStatus statuses={production.statuses} />}
        </article>
    );
}

export function ProjectProgressPanel({ progress, projects = [] }) {
    const options = projects.length ? projects : (progress ? [progress] : []);
    const [selectedProjectId, setSelectedProjectId] = useState(progress?.project_id || options[0]?.project_id || '');
    const selectedProject = options.find((project) => project.project_id === selectedProjectId) || options[0];

    if (!selectedProject) return null;

    return (
        <article className="surface cockpit-progress" aria-label="项目推进">
            <div className="cockpit-progress-head">
                <p>项目推进</p>
                <div className="cockpit-project-switcher">
                    <label htmlFor="cockpit-project-select">切换项目</label>
                    <select
                        id="cockpit-project-select"
                        value={selectedProject.project_id}
                        onChange={(event) => setSelectedProjectId(event.target.value)}
                    >
                        {options.map((project) => (
                            <option value={project.project_id} key={project.project_id}>
                                {project.project_no} · {project.project_name}
                            </option>
                        ))}
                    </select>
                    <Link href={selectedProject.url} aria-label={`查看${selectedProject.project_name}项目详情`}>
                        <ArrowRight size={15} aria-hidden="true" />
                    </Link>
                </div>
            </div>
            <div className="cockpit-progress-grid">
                {selectedProject.steps.map((step) => (
                    <div className={`cockpit-progress-step ${step.state}`} key={step.label}>
                        <span>{step.label}</span>
                        <strong>{step.state === 'done' ? '已过' : (step.state === 'current' ? '当前' : '待推进')}</strong>
                    </div>
                ))}
            </div>
        </article>
    );
}

export function CockpitEmpty() {
    return (
        <section className="surface cockpit-empty" aria-label="经营驾驶舱暂无数据">
            <Gauge size={28} aria-hidden="true" />
            <div><strong>暂无可展示的经营数据</strong><span>当前账号没有获授权的来源对象，或已有对象尚无记录。</span></div>
        </section>
    );
}

function WorkOrderStatus({ statuses }) {
    const total = statuses.reduce((sum, item) => sum + item.count, 0);

    return (
        <div className="cockpit-work-orders" aria-label={statuses.map((item) => `${item.status}${item.count}个`).join('，')}>
            <div className="cockpit-status-strip" aria-hidden="true">
                {statuses.map((item, index) => (
                    item.count > 0 && <span key={item.status} style={{ flex: item.count, background: workOrderColors[index] }} />
                ))}
            </div>
            <div className="cockpit-status-legend">
                {statuses.map((item, index) => <span key={item.status}><i style={{ background: workOrderColors[index] }} />{item.status} {item.count}</span>)}
            </div>
            {total === 0 && <span className="muted">暂无生产任务</span>}
        </div>
    );
}

function PanelHeader({ eyebrow, title, id, href, action }) {
    return (
        <div className="cockpit-panel-head">
            <div><p>{eyebrow}</p><h2 id={id}>{title}</h2></div>
            {href && <Link href={href}>{action}<ArrowRight size={14} aria-hidden="true" /></Link>}
        </div>
    );
}

function ChartEmpty({ text }) {
    return <div className="cockpit-chart-empty"><AlertTriangle size={18} aria-hidden="true" /><span>{text}</span></div>;
}

function formatKpi(kpi) {
    if (kpi.value === null || !Number.isFinite(Number(kpi.value))) return { value: '—', unit: '' };
    if (kpi.format === 'percentage') return { value: Number(kpi.value).toFixed(1), unit: '%' };

    return { value: formatWan(kpi.value), unit: '万元' };
}

function formatWan(value) {
    if (value === null || !Number.isFinite(Number(value))) return '—';

    return (Number(value) / 10000).toLocaleString('zh-CN', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
}

function formatAmount(value) {
    if (value === null || !Number.isFinite(Number(value))) return '—';

    return `${Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} 元`;
}

function formatPanelAsOf(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';

    return new Intl.DateTimeFormat('zh-CN', {
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    }).format(date).replaceAll('/', '-');
}

function formatTon(value) {
    if (value === null || !Number.isFinite(Number(value))) return '—';

    return `${formatNumber(value)} 吨`;
}

function formatNumber(value) {
    return Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 2 });
}

function coverageText(coverage) {
    return coverage ? `覆盖 ${coverage.valid}/${coverage.total}` : '';
}

function barWidth(value, maximum) {
    if (!Number.isFinite(Number(value)) || maximum <= 0) return 0;

    return Math.max(Number(value) / maximum * 100, 2);
}

function tenderTone(status) {
    if (status === '已中标') return 'success';
    if (status === '未中标') return 'danger';
    if (status === '已放弃') return 'muted';

    return '';
}
