import { useState } from 'react';

const formatAmount = (amount) => (amount / 10000).toLocaleString('zh-CN', { maximumFractionDigits: 2 });

export default function AnnualOutputChart({ series = [], summary = null }) {
    const [active, setActive] = useState(null);
    const rows = series.filter((row) => Number.isInteger(row.year) && Number.isFinite(row.amount)).sort((a, b) => a.year - b.year);
    const min = Math.min(0, ...rows.map((row) => row.amount));
    const max = Math.max(1, ...rows.map((row) => row.amount));
    const first = rows[0]?.year ?? 0;
    const last = rows.at(-1)?.year ?? first;
    const points = rows.map((row) => ({ ...row, x: last === first ? 225 : 48 + (row.year - first) / (last - first) * 360, y: 112 - (row.amount - min) / (max - min) * 86 }));
    return <section className="viz-annual-output" aria-label="年度产值变化">
        <div className="viz-annual-heading"><h3>年度产值变化</h3><span>万元</span></div>
        {rows.length ? <svg viewBox="0 0 440 148" role="img" aria-label="按项目发生年份归集金额折线图">
            {[0, 0.5, 1].map((portion) => <g key={portion}><line x1="48" x2="408" y1={112 - portion * 86} y2={112 - portion * 86} className="viz-annual-grid" /><text x="42" y={116 - portion * 86} textAnchor="end">{formatAmount(min + portion * (max - min))}</text></g>)}
            {points.length > 1 && <polyline className="viz-annual-line" points={points.map((point) => `${point.x},${point.y}`).join(' ')} />}
            {points.map((point) => <g key={point.year}><circle cx={point.x} cy={point.y} r="4" tabIndex="0" role="button" aria-label={`${point.year}年产值 ${formatAmount(point.amount)} 万元`} onMouseEnter={() => setActive(point.year)} onMouseLeave={() => setActive(null)} onFocus={() => setActive(point.year)} onBlur={() => setActive(null)}><title>{point.year}年：{formatAmount(point.amount)} 万元</title></circle><text x={point.x} y="136" textAnchor="middle">{point.year}</text></g>)}
        </svg> : <div className="viz-annual-empty" role="status">{summary ? '暂无明确发生年份且金额已维护的项目' : '待接入逐年实际发生明细'}</div>}
        {summary && <p className="viz-annual-coverage" title={`年份不明 ${summary.unknown_year} · 跨年或冲突 ${summary.ambiguous_year} · 金额缺失 ${summary.missing_amount}`}>按项目发生年份归集 · {summary.covered}/{summary.total} 项目</p>}
        {active !== null && <div className="viz-annual-tooltip" role="tooltip">{active}年 · {formatAmount(rows.find((row) => row.year === active).amount)} 万元</div>}
    </section>;
}
