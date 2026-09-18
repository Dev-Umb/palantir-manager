import { Head, Link, router } from '@inertiajs/react';
import './hub.css';
import Layout from '../../Components/Layout';

export const root = '/procurement-hub';
export const kinds = { notice: '采购公告', amendment: '补遗变更', candidate: '候选人公示', award: '成交结果', termination: '终止公告', intent: '采购意向' };
export const statuses = { withdrawn: '已撤回', queued: '等待处理', running: '正在分析', published: '已发布', completed: '已完成', failed: '暂时失败', insufficient: '资料不足', stale: '需要更新', cancelled: '已取消', draft: '待确认', confirmed: '已确认', candidate: '候选来源', active: '采集中', pending: '等待采集', error: '采集异常', needs_review: '需检查入口' };
export const reportSections = { decision: '值得不值得跟进', match: '与已有项目的匹配点', qualification: '资格缺口与待核实条件', counterexample: '历史反例', price: '历史价格参考', window: '报名与投标时间', action: '具体行动建议', market: '历史采购研究' };
export const stages = { plan: '统筹任务', discover: '发现公告', collect: '采集证据', gather: '补查历史', analyze: '数据分析', recommend: '综合推荐', audit: '结果审计' };
export const inputClass = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm';
export const primary = 'inline-flex items-center justify-center rounded-lg bg-steel px-4 py-2 text-sm font-medium text-white disabled:opacity-50';
export const secondary = 'hub-secondary inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm';
export const date = (value) => value ? new Date(value).toLocaleString('zh-CN', { timeZone: 'Asia/Shanghai', hour12: false }) : '未公开';
export const safeUrl = (value) => /^https?:\/\//i.test(value || '') ? value : null;
export const deadlineDate = (value) => {
    if (!value || !Number.isFinite(new Date(value).getTime())) return '待核实';
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return `${value}（具体时刻未公开）`;
    return new Date(value).toLocaleString('zh-CN', { timeZone: 'Asia/Shanghai', hour12: false, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
};

export function Shell({ title, children }) {
    return <Layout title={title} eyebrow="招采信息中心"><Head title={title} /><div className="hub-content flex min-w-0 flex-col gap-5">{children}</div></Layout>;
}
export function Panel({ title, children }) {
    return <section className="min-w-0 rounded-xl border border-slate-200 bg-white p-5">{title && <h2 className="mb-4 text-lg font-semibold">{title}</h2>}{children}</section>;
}
export function Empty({ children }) {
    return <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-10 text-center text-slate-500">{children}</div>;
}
export function Errors({ errors = {} }) {
    return Object.keys(errors).length > 0 && <div role="alert" className="rounded-lg bg-red-50 p-3 text-red-800">{Object.entries(errors).map(([key, value]) => <p key={key}>{value}</p>)}</div>;
}
export function Field({ label, children }) {
    return <label className="flex min-w-0 flex-col gap-1.5 text-sm font-medium">{label}{children}</label>;
}
export function Badge({ children }) {
    return <span className="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-600">{children}</span>;
}
export function Pages({ links = [] }) {
    return <nav aria-label="分页" className="flex flex-wrap gap-2">{links.map((link, i) => link.url ? <Link key={i} href={link.url} className={link.active ? primary : secondary}>{link.label.replace(/pagination\.previous|Previous/g, '上一页').replace(/pagination\.next|Next/g, '下一页').replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/<[^>]*>/g, '')}</Link> : null)}</nav>;
}
export function NoticeCard({ notice, briefing, bookmarked, showBookmark = true }) {
    return <article className="rounded-xl border border-slate-200 bg-white p-5 transition-shadow hover:shadow-sm">
        <div className="mb-3 flex flex-wrap items-center gap-2"><Badge>{kinds[notice.kind] || notice.kind}</Badge><span className="text-xs text-slate-500">{notice.region || '地区未公开'} · {date(notice.published_at)}</span>{notice.has_update && <Badge>有更新</Badge>}</div>
        <Link className="text-lg font-semibold leading-relaxed text-slate-900 hover:text-steel" href={`${root}/notices/${notice.id}`}>{notice.title}</Link>
        <p className="mt-2 text-sm text-slate-600">{notice.buyer || '采购主体待核实'}{notice.lot && ` · 包件 ${notice.lot}`}</p>
        <div className="mt-4 rounded-lg bg-slate-50 p-3"><dl className="grid gap-3 text-sm sm:grid-cols-3">{[['报名开始', notice.registration?.start], ['报名截止', notice.registration?.end], ['投标截止', notice.deadline]].map(([label, value]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 font-medium text-slate-800">{deadlineDate(value)}</dd></div>)}</dl><p className="mt-2 text-xs text-slate-400">北京时间 · 报名与投标截止分别核对，以最新原文为准</p></div>
        {notice.recommendation_summary && <p className="mt-4 rounded-lg bg-emerald-50 p-3 text-sm leading-7"><span className="font-medium">已审计跟进建议：</span>{notice.recommendation_summary}</p>}{briefing && !notice.recommendation_summary && <div className="mt-4 text-sm leading-7"><p className="font-medium text-slate-800">{briefing.recommendation}</p>{briefing.conditions.length > 0 && <p className="mt-2 text-slate-500">原文条件：{briefing.conditions[0].slice(0, 130)}{briefing.conditions[0].length > 130 ? '…' : ''}</p>}<p className="mt-2 text-xs text-slate-400">{briefing.status} · 详细条件、限制与证据见分析报告</p></div>}
        {notice.screening && <Screening value={notice.screening} />}
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3"><div className="text-sm text-slate-500">{notice.facts?.amount ? `${notice.facts.currency ? notice.facts.currency + " " : ""}${notice.facts.amount} · ${notice.facts.amount_type === 'budget' ? '预算' : notice.facts.amount_type === 'candidate' ? '候选人报价' : notice.facts.amount_type === 'award' ? '成交金额' : '原文金额'}` : '金额待核实'}{notice.recommendation_score != null && <span className="ml-4 text-steel">跟进优先级 {Number(notice.recommendation_score)}</span>}</div>
        <div className="flex gap-2"><Link className={secondary} href={`${root}/notices/${notice.id}`}>{briefing ? '查看分析报告' : '查看详情'}</Link>{showBookmark && <button className={secondary} onClick={() => router.post(`${root}/notices/${notice.id}/bookmark`, {}, { preserveScroll: true })}>{bookmarked ? '取消收藏' : '收藏'}</button>}</div></div>
    </article>;
}
export function Screening({ value }) {
    return <div className="mt-4 rounded-lg bg-sky-50 p-4 text-sm"><p className="font-medium text-sky-900">{value.label}</p>{value.reasons?.map((reason, i) => <p className="mt-2 leading-6 text-slate-700" key={i}>{reason}</p>)}{value.projects?.length > 0 && <div className="mt-3 border-t border-sky-100 pt-3"><span className="text-xs text-slate-500">参考项目主档</span>{value.projects.map((project) => <p key={project.id} className="mt-1 text-slate-700">{project.title} <span className="text-xs text-slate-500">· {project.terms.join('、')}</span></p>)}</div>}<p className="mt-3 text-xs leading-6 text-slate-500">{value.limitation}</p></div>;
}
export function Brief({ value, compact = false }) {
    return <Panel title={compact ? value.title : '采购核对报告'}><div className="flex flex-wrap items-center gap-2"><Badge>{value.status}</Badge><span className="text-xs text-slate-500">{date(value.fetched_at)}</span></div><p className="mt-4 font-medium leading-7 text-slate-800">{value.recommendation}</p><p className="mt-2 text-sm leading-7 text-slate-600">{value.screening.reasons[0]}</p>
        {value.conditions.length > 0 && <div className="mt-4"><h3 className="text-sm font-medium">原文中需要核对的条件</h3><ul className="mt-2 space-y-2">{value.conditions.slice(0, compact ? 2 : 5).map((text, i) => <li key={i} className="rounded-lg bg-slate-50 p-3 text-sm leading-7">{text}</li>)}</ul></div>}
        {!compact && value.counterexamples.length > 0 && <div className="mt-4 rounded-lg bg-amber-50 p-4 text-sm leading-7"><h3 className="font-medium">主档中的反例提醒</h3>{value.counterexamples.map((p,i) => <p key={i}>{p.title}：{p.quote}</p>)}<p className="mt-2 text-xs">这些记录不代表本公告的投标结果；不能由项目存在推断曾中标。</p></div>}
        <p className="mt-4 text-xs leading-6 text-slate-500">{value.limitations.join(' ')}</p><div className="mt-4 flex flex-wrap gap-4 text-sm">{safeUrl(value.source_url) && <a className="text-steel underline" href={value.source_url} target="_blank" rel="noopener noreferrer">{value.source_name || '查看原文'} ↗</a>}{compact && <Link className="text-steel" href={`${root}/notices/${value.notice_id}#analysis`}>查看完整核对报告 →</Link>}</div></Panel>;
}
export function Report({ run }) {
    return <Panel title={run.query || '项目分析'}><div className="mb-4 flex flex-wrap items-center gap-3"><Badge>{statuses[run.status] || run.status}</Badge><span className="text-sm text-slate-500">{run.published_at ? date(run.published_at) : stages[run.stage]}</span>{run.stale_at && <span className="text-sm text-amber-700">依据已更新，以下为历史分析</span>}{run.score && <span className="text-sm font-medium text-steel">跟进优先级 {run.score.value} / 100</span>}</div>
        {run.result && run.published_at ? <div className="flex flex-col gap-5">{['recommendation', 'analysis'].map((key) => <div key={key}><h3 className="mb-2 font-semibold">{key === 'analysis' ? '市场与历史研究' : '企业适配建议'}</h3><ul className="space-y-2">{run.result[key]?.claims?.filter((claim) => key !== 'analysis' || !claim.section || ['price', 'market'].includes(claim.section)).map((claim, i) => <li key={i} className="rounded-lg bg-slate-50 p-3 text-sm leading-7">{reportSections[claim.section] && <h4 className="font-semibold text-slate-900">{reportSections[claim.section]}</h4>}{claim.text}<span className="ml-2 inline-flex flex-wrap gap-2">{claim.evidence_ids?.map((id) => { const evidence = run.evidence?.find((e) => e.id === id); return evidence ? <Link key={id} className="text-steel underline" title={claim.quotes?.find((q) => q.evidence_id === id)?.quote} href={`${root}/notices/${evidence.notice_id}`}>证据 {id}</Link> : <span key={id}>证据 {id}</span>; })}</span></li>)}</ul>{run.result[key]?.limitations?.map((text, i) => <p className="mt-2 text-sm text-amber-800" key={i}>{text}</p>)}</div>)}
        {run.result.recommendation?.project_references?.length > 0 && <details className="text-sm"><summary className="cursor-pointer font-medium">参考项目原字段</summary>{run.result.recommendation.project_references.map((reference, i) => <p key={i} className="mt-2 rounded-lg bg-slate-50 p-3 leading-7">项目 {reference.project_name || reference.project_id} · {reference.field}：{reference.quote}</p>)}</details>}
        <div><h3 className="mb-2 font-semibold">可比价格样本</h3>{run.result.statistics?.groups?.length ? <div className="grid gap-3 md:grid-cols-2">{run.result.statistics.groups.map((g, i) => <div className="rounded-lg bg-slate-50 p-3 text-sm" key={i}><p>{g.basis.product} · {g.basis.region} · {g.basis.year || '年份未知'}</p><p>{g.basis.spec} · {g.basis.tax} · {g.basis.freight} · {g.basis.transaction === 'rental' ? '租赁' : '购买'}</p><p className="mt-2 font-semibold">中位数 {g.median} 元/{g.basis.unit} · {g.count} 个样本</p><p className="text-slate-500">区间 {g.min}–{g.max}；{g.basis.amount_type === 'candidate' ? '候选人报价，非最终成交' : '成交金额口径'}</p></div>)}</div> : <p className="text-sm text-slate-500">暂无口径完整的可比价格，不推算成交单价。</p>}</div>
        {run.result.limitations?.map((text, i) => <p className="text-sm text-slate-500" key={i}>{text}</p>)}</div> : <p className="text-sm text-slate-500">{run.error || (run.status === 'insufficient' ? '现有证据不足以发布结论，请补充资料后重新研究。' : '正式结果将在证据校验和独立审计通过后展示。')}</p>}
        {run.audit_issues?.length > 0 && <ul className="mt-3 space-y-1 text-sm text-amber-800">{run.audit_issues.map((issue, i) => <li key={i}>{issue}</li>)}</ul>}
        <div className="mt-4 flex gap-2">{['queued', 'running'].includes(run.status) && <button className={secondary} onClick={() => router.post(`${root}/runs/${run.id}/cancel`)}>取消任务</button>}{run.status === 'failed' && <button className={secondary} onClick={() => router.post(`${root}/runs/${run.id}/retry`)}>重试</button>}{(run.stale_at || ['insufficient', 'cancelled'].includes(run.status)) && <button className={secondary} onClick={() => router.post(`${root}/research`, { query: run.query, notice_id: run.hub_notice_id })}>重新分析</button>}</div>
    </Panel>;
}
