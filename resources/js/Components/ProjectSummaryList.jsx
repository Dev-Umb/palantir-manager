import { Link } from '@inertiajs/react';
import { formatContractTableAmount, objectRecordHref } from './ObjectGrid';

const columns = [
    ['business_owner_user_id', '负责业务员'], ['customer_id', '客户名称'], ['name', '项目名称'],
    ['last_payment_date', '末次回款日期'], ['unpaid_amount', '未回款金额（万元）'],
    ['contract_status', '合同状态'], ['remark', '备注'],
];

export function shortProjectRemark(value) {
    const characters = Array.from(String(value ?? ''));
    return characters.slice(0, 10).join('') + (characters.length > 10 ? '…' : '');
}

export default function ProjectSummaryList({ records, fields, can, offset = 0, listHref, subtotal }) {
    const visibleColumns = columns.filter(([key]) => fields.some(field => field.key === key));
    return <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full border-collapse text-left text-sm">
            <thead className="bg-slate-50"><tr><th className="border-b p-3">序号</th>{visibleColumns.map(([key, label]) => <th className="border-b p-3 whitespace-nowrap" key={key}>{label}</th>)}<th className="sticky right-0 border-b bg-slate-50 p-3">操作</th></tr></thead>
            <tbody>{records.map((record, index) => <tr key={record.id} className="border-b hover:bg-slate-50">
                <td className="p-3">{offset + index + 1}</td>
                {visibleColumns.map(([key]) => <td key={key} className={`p-3 ${key === 'unpaid_amount' ? 'font-semibold tabular-nums' : ''}`} title={key === 'remark' ? String(record.payload?.remark ?? '') : undefined}>{projectCell(record, key)}</td>)}
                <td className="sticky right-0 bg-white p-3 whitespace-nowrap">
                    <Link className="secondary-button small-action mr-2" href={objectRecordHref('project', record.id, 'detail', listHref)}>查看</Link>
                    {can.update && record.can_update !== false ? <Link className="secondary-button small-action" href={objectRecordHref('project', record.id, 'edit', listHref)}>编辑</Link> : <button className="secondary-button small-action" disabled title="当前项目无编辑权限">编辑</button>}
                </td>
            </tr>)}</tbody>
        </table>
        {!records.length && <p className="p-6 text-slate-500">暂无符合条件的项目</p>}
        {subtotal?.values?.unpaid_amount != null && visibleColumns.some(([key]) => key === 'unpaid_amount') && <p className="border-t p-3 text-sm text-slate-500">筛选结果未回款金额小计：<strong>{formatContractTableAmount(subtotal.values.unpaid_amount)} 万元</strong></p>}
    </div>;
}

function projectCell(record, key) {
    const value = record.payload?.[key];
    if (key === 'name') return record.title || value || '—';
    if (key === 'unpaid_amount') return value === null || value === undefined || value === '' ? '—' : formatContractTableAmount(value);
    if (key === 'last_payment_date') return value || '暂无回款记录';
    if (key === 'customer_id') return projectCustomerName(record.display?.customer_id || value);
    if (key === 'contract_status') {
        const status = record.display?.[key] || value;
        if (!status) return '—';
        const colors = {
            '已签署': 'bg-[#e5f0f5] text-[#39677f]',
            '已有加工函': 'bg-[#e9edf8] text-[#67789e]',
            '未签署': 'bg-[#f4eee1] text-[#937438]',
            '部分签署': 'bg-[#f4eee1] text-[#937438]',
        };
        return <span className={`inline-flex rounded-lg px-2 py-1 text-xs font-medium whitespace-nowrap ${colors[status] || 'bg-slate-100 text-slate-600'}`}>{status}</span>;
    }
    if (key === 'remark') return shortProjectRemark(value) || '—';
    return record.display?.[key] || value || '—';
}

export function projectCustomerName(value) {
    if (typeof value !== 'string' || value === '') return '—';
    return value.replace(/^CUST-\d{8}-\d+\s*·\s*/, '');
}
