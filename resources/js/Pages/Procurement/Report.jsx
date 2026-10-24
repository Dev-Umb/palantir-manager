import { Link } from '@inertiajs/react';
import { base, formatTime } from './shared';
import './report.css';

export function ReportArticle({ notice, standalone = false, warnings = [], batchId }) {
    const p = notice.payload;
    const Heading = standalone ? 'h1' : 'h3';
    return <article id={`notice-${notice.id}`} className="procurement-article" tabIndex={-1}>
        <div className="report-eyebrow">{[p.priority, p.category, p.nature, p.subtype].filter(Boolean).join(' · ')}{notice.invited && ' · 受邀信息 · 限定可见'}</div>
        <Heading>{p.title}</Heading>
        <p><span className="report-label">单位：</span>{p.unit || '未披露'}</p>
        <p className="report-code">采购编号：{p.code || '未披露'} · {p.platform}</p>
        <div className="report-dates">
            <div><span>发布时间</span>{p.published || '未披露'}</div>
            <div><span>报名／文件获取截止</span>{p.registration || '未披露'}</div>
            <div><span>报价／投标截止</span><strong>{p.deadline || '未披露'}</strong></div>
        </div>
        <p><span className="report-label">数量：</span><b className="report-quantity">{p.quantity || '未披露'}</b>　<span className="report-label">物资：</span>{p.material || '未披露'}</p>
        <p><span className="report-label">联系：</span>{p.contacts || '未披露'}</p>
        <p><span className="report-label">初判：</span>{p.judgment || '未披露'}</p>
        <p className="report-terms"><span className="report-label">要点：</span>{p.terms || '未披露'}</p>
        {p.issues && <p className="report-warning"><b>核实提示：</b>{p.issues}</p>}
        <div className="report-source">{/^https?:\/\//i.test(p.url) && <a href={p.url} target="_blank" rel="noopener noreferrer" aria-label={`查看原始来源：${p.platform}`}>{p.platform}原公告 ↗</a>}<span>以平台最新公告及补遗为准；列表来源请按采购编号定位</span></div>
        <div className="report-actions">
            {!standalone && <><a href="#procurement-report-overview">↑ 返回优先级总览</a><Link href={`${base}/notices/${notice.id}`}>独立打开详情 →</Link></>}
            <Link href="/ai">在平台 AI 助手分析 →</Link>
            {(batchId || notice.batch_id) && <Link href={`${base}/batches/${batchId || notice.batch_id}`}>查看上传批次</Link>}
        </div>
        <details className="report-notes"><summary>数据来源与核验说明</summary>
            <p>采集标识：{p.id || '未披露'} · 纳入状态：{p.status || '未披露'} · 框架协议：{p.framework ? '是' : '否'}</p>
            <p>入库于 {formatTime(notice.created_at)} · 北京时间口径</p>
            <p>优先级和初判来自采集端，未验证企业实际资质、产能及投标资格。可让平台 AI 查询招采记录 #{notice.id}。</p>
            {warnings.length > 0 && <ul>{warnings.map((warning, i) => <li key={i}>{warning}</li>)}</ul>}
            {p.evidence?.length > 0 && <><p>来源文件引用（未上传）</p><ul>{p.evidence.map((file, i) => <li key={i}>{file}</li>)}</ul></>}
        </details>
    </article>;
}

export default function Report({ notices }) {
    const included = notices.filter(n => n.payload.status !== '待核实' && n.priority !== '待核实');
    const pending = notices.filter(n => n.payload.status === '待核实' || n.priority === '待核实');
    return <section className="procurement-report" id="procurement-report-overview">
        {included.length > 0 && <><h2>本期优先级总览</h2><div className="report-table-wrap" role="region" aria-label="优先级总览表" tabIndex={0}><table>
            <thead><tr>{['优先级', '项目', '数量', '报名／文件获取', '报价／投标截止', '采购性质'].map(label => <th key={label} scope="col">{label}</th>)}</tr></thead>
            <tbody>{included.map(n => <tr key={n.id}>
                <td><span className={`report-priority ${n.priority}`}>{n.priority}</span>{n.has_issues && <small className="report-issue">核实提示</small>}{n.invited && <small>受邀信息</small>}</td>
                <td><a href={`#notice-${n.id}`}>{n.title}</a><small>{n.payload.category} · {n.payload.platform}</small></td>
                <td>{n.payload.quantity}</td><td>{n.payload.registration}</td><td>{n.payload.deadline}</td><td>{n.method} · {n.payload.subtype || '未披露'}</td>
            </tr>)}</tbody>
        </table></div><h2>项目明细</h2>{included.map(n => <ReportArticle key={n.id} notice={n}/>)}</>}
        {pending.length > 0 && <><h2 className="report-pending">待核实清单</h2><p className="report-muted">以下项目资料或时间精度待核实，尚未纳入确认清单；保留全部原始信息，不推定未公布内容。</p><nav className="report-pending-links" aria-label="待核实项目">{pending.map(n => <a key={n.id} href={`#notice-${n.id}`}>{n.title}</a>)}</nav>{pending.map(n => <ReportArticle key={n.id} notice={n}/>)}</>}
    </section>;
}
