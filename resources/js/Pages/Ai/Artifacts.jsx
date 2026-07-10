import 'ag-grid-community/styles/ag-grid.css';
import 'ag-grid-community/styles/ag-theme-quartz.css';
import DOMPurify from 'dompurify';
import {
    CellStyleModule,
    ClientSideRowModelModule,
    ColumnApiModule,
    NumberFilterModule,
    TextFilterModule,
} from 'ag-grid-community';
import { AgGridProvider, AgGridReact } from 'ag-grid-react';
import { ChartColumn, Maximize2, ShieldCheck, TableProperties } from 'lucide-react';
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

export default function Artifact({ artifact }) {
    if (artifact.type === 'table') return <TableArtifact artifact={artifact} />;
    if (artifact.type === 'chart') return <ChartArtifact artifact={artifact} />;
    if (artifact.type === 'html') return <HtmlArtifact artifact={artifact} />;
    return null;
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
