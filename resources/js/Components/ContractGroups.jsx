import { Link } from '@inertiajs/react';
import { useState } from 'react';
import AttachmentTray from './AttachmentTray';
import { contractTableFields, formatContractTableAmount } from './ObjectGrid';

export function summarizeContracts(records) {
    const amounts = records.map(record => record.payload?.amount);
    const known = amounts.filter(value => value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value)));
    const dates = records.map(record => record.payload?.signed_date).filter(value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)).sort();
    return {
        amount: known.length ? formatContractTableAmount(known.reduce((sum, value) => sum + Number(value), 0)) : '金额待补充',
        missingAmounts: records.length - known.length,
        dates: dates.length ? `${dates[0]} 至 ${dates[dates.length - 1]}` : '日期待补充',
        missingDates: records.length - dates.length,
    };
}

export default function ContractGroups({ groups, fields, listHref, subtotal = null }) {
    const [expanded, setExpanded] = useState({});
    const detailFields = contractTableFields('contract', fields);
    return <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full border-collapse text-left text-sm">
            <thead className="bg-slate-50"><tr>{['序号', '负责业务员', '客户名称', '项目名称', '合同金额合计（万元）', '签订日期区间', '合同份数', '操作'].map(label => <th key={label} className="border-b p-3 whitespace-nowrap">{label}</th>)}</tr></thead>
            <tbody>{groups.data.map((records, index) => {
                const first = records[0];
                const key = JSON.stringify([first.payload?.project_id, first.payload?.customer_id, first.id]);
                const summary = summarizeContracts(records);
                const isExpanded = !!expanded[key];
                return <ContractGroup key={key} records={records} first={first} summary={summary} fields={detailFields} listHref={listHref || '/objects/contract'} expanded={isExpanded} sequence={(groups.current_page - 1) * groups.per_page + index + 1} onToggle={() => setExpanded(value => ({ ...value, [key]: !isExpanded }))} />;
            })}</tbody>
        </table>
        {groups.data.length === 0 && <p className="p-6 text-slate-500">暂无符合条件的合同</p>}
        <p className="border-t p-3 text-sm text-slate-500">共 {groups.total} 组；汇总仅包含当前筛选结果内可见的合同。{subtotal?.values?.amount != null && <strong className="ml-3">筛选结果金额小计：{formatContractTableAmount(subtotal.values.amount)} 万元</strong>}</p>
    </div>;
}

function ContractGroup({ records, first, summary, fields, listHref, expanded, sequence, onToggle }) {
    return <>
        <tr className="border-b">
            <td className="p-3">{sequence}</td>
            <td className="p-3">{first.display?.business_owner_name || '—'}</td>
            <td className="p-3">{first.display?.customer_id || '未关联客户'}</td>
            <td className="p-3">{first.display?.project_id || '未关联项目'}</td>
            <td className="p-3"><strong>{summary.amount}</strong>{summary.missingAmounts > 0 && <div className="text-xs text-amber-700">{summary.missingAmounts} 份金额待补充{summary.missingAmounts < records.length ? '，当前为已知金额合计' : ''}</div>}</td>
            <td className="p-3">{summary.dates}{summary.missingDates > 0 && <div className="text-xs text-amber-700">{summary.missingDates} 份日期待补充</div>}</td>
            <td className="p-3">{records.length}</td>
            <td className="p-3"><button type="button" className="secondary-button small-action" aria-expanded={expanded} onClick={onToggle}>{expanded ? '收起合同' : '展开合同'}</button></td>
        </tr>
        {expanded && <tr><td colSpan={8} className="bg-slate-50 p-4">
            {records.map((record, index) => <section key={record.id} className="mb-3 rounded-lg border border-slate-200 bg-white p-4">
                <div className="mb-3 flex items-center justify-between"><strong>合同 {index + 1}</strong><Link className="icon-link" href={`${listHref}${listHref.includes('?') ? '&' : '?'}record=${record.id}&mode=detail`}>查看独立合同</Link></div>
                <dl className="grid grid-cols-1 gap-3 md:grid-cols-3">{fields.map(field => <div key={field.key}><dt className="text-xs text-slate-500">{field.label}</dt><dd className="mt-1">{contractValue(record, field)}</dd></div>)}</dl>
            </section>)}
        </td></tr>}
    </>;
}

function contractValue(record, field) {
    if (['files', 'file'].includes(field.type)) {
        const previews = record.attachment_previews?.[field.key] || [];
        if (previews.length) return <AttachmentTray files={previews} label={field.label} />;
        const raw = record.payload?.[field.key];
        const urls = Array.isArray(raw) ? raw : raw ? [raw] : [];
        return urls.length ? urls.map((url, index) => <a className="icon-link mr-2" key={url} href={url} target="_blank" rel="noreferrer">附件 {index + 1}</a>) : '—';
    }
    const value = field.key === 'amount' ? formatContractTableAmount(record.payload?.amount) : record.display?.[field.key] ?? record.payload?.[field.key];
    if (value === null || value === undefined || value === '') return '—';
    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}
