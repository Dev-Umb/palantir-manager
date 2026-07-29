import 'ag-grid-community/styles/ag-grid.css';
import 'ag-grid-community/styles/ag-theme-quartz.css';
import { Link } from '@inertiajs/react';
import DOMPurify from 'dompurify';
import {
    CellStyleModule,
    ClientSideRowModelModule,
    ColumnApiModule,
    NumberFilterModule,
    TextFilterModule,
} from 'ag-grid-community';
import { AgGridProvider, AgGridReact } from 'ag-grid-react';
import {
    BadgeCheck,
    ChartColumn,
    CircleAlert,
    CircleX,
    ListChecks,
    LoaderCircle,
    Maximize2,
    ShieldCheck,
    SendHorizontal,
    TableProperties,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Legend,
    Line,
    LineChart,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

const gridModules = [CellStyleModule, ClientSideRowModelModule, ColumnApiModule, NumberFilterModule, TextFilterModule];
const chartColors = ['#2f6f9f', '#2f8f6b', '#b7791f', '#c2413d', '#667085', '#7a5aa6'];

export default function Artifact({ artifact, onQuickReply, onProposalAction, canAct = true }) {
    if (artifact.type === 'table') return <TableArtifact artifact={artifact} />;
    if (artifact.type === 'chart') return <ChartArtifact artifact={artifact} />;
    if (artifact.type === 'html') return <HtmlArtifact artifact={artifact} />;
    if (artifact.type === 'choice') return <ChoiceArtifact artifact={artifact} onQuickReply={onQuickReply} canAct={canAct} />;
    if (artifact.type === 'form') return <FormArtifact artifact={artifact} onQuickReply={onQuickReply} canAct={canAct} />;
    if (artifact.type === 'write_proposal' || artifact.type === 'update_proposal') {
        return <WriteProposalArtifact artifact={artifact} onProposalAction={onProposalAction} canAct={canAct} />;
    }
    return null;
}

export function FormArtifact({ artifact, onQuickReply, canAct = true }) {
    const fields = artifact.data?.fields || [];
    const [values, setValues] = useState(() => Object.fromEntries(fields.map((field) => [field.key, ''])));
    const [errors, setErrors] = useState({});
    const [submitted, setSubmitted] = useState(false);

    if (fields.length === 0) return null;

    function submit(event) {
        event.preventDefault();
        const nextErrors = Object.fromEntries(fields
            .filter((field) => field.required && String(values[field.key] ?? '').trim() === '')
            .map((field) => [field.key, `请填写${field.label}`]));
        setErrors(nextErrors);
        if (Object.keys(nextErrors).length > 0) return;

        const suppliedFields = fields
            .filter((field) => String(values[field.key] ?? '').trim() !== '')
            .map((field) => `${field.label}（${field.key}）：${values[field.key]}`);
        setSubmitted(true);
        onQuickReply?.(`我已补充创建资料，请继续生成待确认卡片。\n${suppliedFields.join('\n')}`);
    }

    return (
        <section className="ai-artifact ai-form-artifact">
            <ArtifactHeading artifact={artifact} icon={<ListChecks size={15} />} badge={submitted ? '已提交' : '待补充'} />
            <form className="ai-form-body" onSubmit={submit}>
                <p>{artifact.data?.question}</p>
                <div className="ai-form-fields">
                    {fields.map((field) => (
                        <label key={field.key}>
                            <span>{field.label}{field.required && <em>*</em>}</span>
                            {field.type === 'select' ? (
                                <select
                                    value={values[field.key] ?? ''}
                                    disabled={!canAct || submitted}
                                    onChange={(event) => setValues((current) => ({ ...current, [field.key]: event.target.value }))}
                                >
                                    <option value="">请选择</option>
                                    {(field.options || []).map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                            ) : field.type === 'textarea' ? (
                                <textarea
                                    rows={3}
                                    value={values[field.key] ?? ''}
                                    placeholder={field.placeholder || ''}
                                    disabled={!canAct || submitted}
                                    onChange={(event) => setValues((current) => ({ ...current, [field.key]: event.target.value }))}
                                />
                            ) : (
                                <input
                                    type={field.type}
                                    value={values[field.key] ?? ''}
                                    placeholder={field.placeholder || ''}
                                    disabled={!canAct || submitted}
                                    step={field.type === 'number' ? 'any' : undefined}
                                    onChange={(event) => setValues((current) => ({ ...current, [field.key]: event.target.value }))}
                                />
                            )}
                            {errors[field.key] && <small>{errors[field.key]}</small>}
                        </label>
                    ))}
                </div>
                <button type="submit" className="action-button" disabled={!canAct || submitted}>
                    <SendHorizontal size={15} />
                    {submitted ? '已提交' : (artifact.data?.submit_label || '提交并继续')}
                </button>
            </form>
        </section>
    );
}

export function ChoiceArtifact({ artifact, onQuickReply, canAct = true }) {
    const options = artifact.data?.options || [];
    if (options.length === 0) return null;

    return (
        <section className="ai-artifact ai-choice-artifact">
            <ArtifactHeading artifact={artifact} icon={<ListChecks size={15} />} badge="请选择" />
            <div className="ai-choice-body">
                <p>{artifact.data?.question}</p>
                <div>
                    {options.map((option, index) => (
                        <button
                            type="button"
                            key={`${option.value}-${index}`}
                            disabled={!canAct}
                            onClick={() => onQuickReply?.(option.value)}
                        >
                            <strong>{option.label}</strong>
                            {option.description && <span>{option.description}</span>}
                        </button>
                    ))}
                </div>
            </div>
        </section>
    );
}

export function WriteProposalArtifact({ artifact, onProposalAction, canAct = true }) {
    const [processing, setProcessing] = useState('');
    const [error, setError] = useState('');
    const status = artifact.data?.status || 'pending';
    const record = artifact.data?.record;
    const isUpdate = artifact.type === 'update_proposal';

    async function act(action) {
        setProcessing(action);
        setError('');
        try {
            await onProposalAction?.(artifact.id, action);
        } catch (requestError) {
            setError(requestError.message || '操作失败，请稍后重试。');
        } finally {
            setProcessing('');
        }
    }

    return (
        <section className={`ai-artifact ai-write-proposal ${status}`}>
            <ArtifactHeading
                artifact={artifact}
                icon={status === 'confirmed' ? <BadgeCheck size={15} /> : <ShieldCheck size={15} />}
                badge={proposalStatusLabel(status)}
            />
            <div className="ai-proposal-body">
                <p className="ai-proposal-warning">
                    {status === 'pending'
                        ? (canAct
                            ? (isUpdate
                                ? '请核对修改前后差异。只有点击“确认修改”后才会更新业务数据。'
                                : '请核对以下信息。只有点击“确认写入”后才会新增业务数据。')
                            : `AI 正在完成资料校验，任务结束后即可确认${isUpdate ? '修改' : '写入'}。`)
                        : proposalStatusMessage(status, isUpdate)}
                </p>
                {isUpdate ? (
                    <div className="ai-proposal-diff">
                        <div className="ai-proposal-diff-heading"><span>字段</span><span>修改前</span><span>修改后</span></div>
                        {(artifact.data?.changes || []).map((field) => (
                            <div key={field.key}>
                                <strong>{field.label}</strong>
                                <span>{formatCell(field.before)}</span>
                                <span>{formatCell(field.after)}</span>
                            </div>
                        ))}
                    </div>
                ) : (
                    <dl>
                        {(artifact.data?.fields || []).map((field) => (
                            <div key={field.key}>
                                <dt>{field.label}</dt>
                                <dd>{formatCell(field.value)}</dd>
                            </div>
                        ))}
                    </dl>
                )}
                {status === 'pending' && (
                    <div className="ai-proposal-actions">
                        <button type="button" className="ghost-button" disabled={!canAct || Boolean(processing)} onClick={() => act('reject')}>
                            {processing === 'reject' ? <LoaderCircle className="spin" size={15} /> : <CircleX size={15} />}
                            放弃
                        </button>
                        <button type="button" className="action-button" disabled={!canAct || Boolean(processing)} onClick={() => act('confirm')}>
                            {processing === 'confirm' ? <LoaderCircle className="spin" size={15} /> : <BadgeCheck size={15} />}
                            {isUpdate ? '确认修改' : '确认写入'}
                        </button>
                    </div>
                )}
                {status === 'confirmed' && record?.url && (
                    <Link className="ai-proposal-record-link" href={record.url}>
                        {isUpdate ? '查看已更新资料' : '查看已创建资料'}：{record.code}
                    </Link>
                )}
                {error && <div className="ai-proposal-error"><CircleAlert size={15} />{error}</div>}
            </div>
        </section>
    );
}

function TableArtifact({ artifact }) {
    const rows = artifact.data?.rows || [];
    const columnDefs = useMemo(() => (artifact.data?.columns || []).map((column) => ({
        field: column.key,
        headerName: column.label,
        minWidth: column.type === 'number' ? 120 : 150,
        flex: 1,
        filter: column.type === 'number' ? 'agNumberColumnFilter' : 'agTextColumnFilter',
        valueFormatter: ({ value }) => formatCell(value),
    })), [artifact]);

    if (rows.length === 0) return null;

    return (
        <section className="ai-artifact ai-table-artifact">
            <ArtifactHeading artifact={artifact} icon={<TableProperties size={15} />} />
            <div className="ag-theme-quartz" style={{ height: Math.min(430, Math.max(180, rows.length * 42 + 64)) }}>
                <AgGridProvider modules={gridModules}>
                    <AgGridReact
                        theme="legacy"
                        rowData={rows}
                        columnDefs={columnDefs}
                        defaultColDef={{ sortable: true, resizable: true, filter: true }}
                        suppressCellFocus
                    />
                </AgGridProvider>
            </div>
        </section>
    );
}

function ChartArtifact({ artifact }) {
    const chart = artifact.data || {};
    const rows = chart.rows || [];
    if (!rows.length) return null;

    return (
        <section className="ai-artifact ai-chart-artifact">
            <ArtifactHeading artifact={artifact} icon={<ChartColumn size={15} />} />
            <div className="ai-rechart">
                <ResponsiveContainer width="100%" height="100%">
                    {chart.type === 'line' ? (
                        <LineChart data={rows} margin={{ top: 12, right: 20, left: 4, bottom: 8 }}>
                            <CartesianGrid strokeDasharray="3 3" vertical={false} />
                            <XAxis dataKey={chart.x} tickLine={false} axisLine={false} />
                            <YAxis tickLine={false} axisLine={false} />
                            <Tooltip />
                            <Line type="monotone" dataKey={chart.y} stroke={chartColors[0]} strokeWidth={2} dot={{ r: 3 }} />
                        </LineChart>
                    ) : chart.type === 'pie' ? (
                        <PieChart>
                            <Pie data={rows} dataKey={chart.y} nameKey={chart.x} innerRadius={58} outerRadius={100} paddingAngle={2}>
                                {rows.map((_, index) => <Cell key={index} fill={chartColors[index % chartColors.length]} />)}
                            </Pie>
                            <Tooltip />
                            <Legend />
                        </PieChart>
                    ) : (
                        <BarChart data={rows} margin={{ top: 12, right: 20, left: 4, bottom: 8 }}>
                            <CartesianGrid strokeDasharray="3 3" vertical={false} />
                            <XAxis dataKey={chart.x} tickLine={false} axisLine={false} />
                            <YAxis tickLine={false} axisLine={false} />
                            <Tooltip />
                            <Bar dataKey={chart.y} fill={chartColors[0]} radius={[4, 4, 0, 0]} maxBarSize={54} />
                        </BarChart>
                    )}
                </ResponsiveContainer>
            </div>
        </section>
    );
}

export function HtmlArtifact({ artifact }) {
    const [expanded, setExpanded] = useState(false);
    const document = useMemo(() => htmlDocument(artifact.data?.html || ''), [artifact]);

    return (
        <section className="ai-artifact ai-html-artifact">
            <ArtifactHeading artifact={artifact} icon={<ShieldCheck size={15} />} badge="静态 HTML" />
            <iframe
                title={artifact.title || 'HTML 分析结果'}
                srcDoc={document}
                sandbox=""
                referrerPolicy="no-referrer"
                style={{ height: expanded ? 640 : 360 }}
            />
            <button type="button" className="ai-expand-html" onClick={() => setExpanded((value) => !value)}>
                <Maximize2 size={14} /> {expanded ? '收起' : '展开查看'}
            </button>
        </section>
    );
}

function ArtifactHeading({ artifact, icon, badge }) {
    return (
        <header className="ai-artifact-heading">
            <div>{icon}<strong>{artifact.title || '分析结果'}</strong></div>
            {badge && <span>{badge}</span>}
        </header>
    );
}

export function htmlDocument(html) {
    const safe = DOMPurify.sanitize(html, {
        USE_PROFILES: { html: true },
        FORBID_TAGS: ['script', 'form', 'input', 'button', 'select', 'textarea', 'iframe', 'object', 'embed', 'link', 'meta', 'base'],
        FORBID_ATTR: ['srcset', 'formaction'],
        ALLOW_DATA_ATTR: false,
    });
    const csp = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src 'none'; connect-src 'none'; frame-src 'none'; form-action 'none'; base-uri 'none'";

    return `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="${csp}"><meta name="viewport" content="width=device-width,initial-scale=1"><style>html{color:#151b22;background:#fff;font:14px/1.65 Inter,system-ui,sans-serif}body{margin:0;padding:18px}table{width:100%;border-collapse:collapse}th,td{padding:8px 10px;border:1px solid #e3e7ec;text-align:left}th{background:#f5f7f9}*{box-sizing:border-box;max-width:100%}</style></head><body>${safe}</body></html>`;
}

function formatCell(value) {
    if (value === null || value === undefined || value === '') return '-';
    if (typeof value === 'number') return new Intl.NumberFormat('zh-CN', { maximumFractionDigits: 2 }).format(value);
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

function proposalStatusLabel(status) {
    return {
        pending: '等待确认',
        confirmed: '已写入',
        rejected: '已放弃',
        expired: '已过期',
    }[status] || status;
}

function proposalStatusMessage(status, isUpdate = false) {
    return {
        confirmed: `资料已由当前用户确认并${isUpdate ? '更新' : '写入'}。`,
        rejected: `本次${isUpdate ? '修改' : '写入'}已放弃，业务数据没有变化。`,
        expired: '该确认卡片已过期，请让 AI 重新生成。',
        stale: '原资料已发生变化，本卡片未执行，请让 AI 重新读取后生成。',
    }[status] || '该确认卡片当前不可操作。';
}
