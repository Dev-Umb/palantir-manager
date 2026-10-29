import { Link } from '@inertiajs/react';
import './reimbursements.css';
export { request } from '../SupportLibrary/api';
export const types = ['住宿费', '加油费', '话费', '高速费', '差旅交通', '客户招待', '办公采购', '其他'];
export const statuses = { pending: '审批中', approved: '审批通过', rejected: '已驳回' };
export const money = (cents) => (cents / 100).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
export function Status({ value }) { return <span className={`oa-status ${value}`}>{statuses[value] || value}</span>; }
export function Pages({ data }) { return <nav className="oa-actions" aria-label="分页"><span>共 {data.total} 条 · 第 {data.current_page} 页</span>{data.prev_page_url && <Link href={data.prev_page_url}>上一页</Link>}{data.next_page_url && <Link href={data.next_page_url}>下一页</Link>}</nav>; }
