import { Head, Link } from '@inertiajs/react';
import Layout from '../../Components/Layout';
export const base = '/procurement-hub';
export const fieldClass = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 w-full';
export function Shell({title, children, hideHeader=false}) { return <Layout title={title} eyebrow="招采信息中心" hideHeader={hideHeader}><Head title={title}/><div className="mx-auto flex w-full max-w-7xl flex-col gap-5">{children}</div></Layout>; }
export function Panel({title, children}) { return <section className="min-w-0 rounded-xl border border-slate-200 bg-white p-5">{title && <h2 className="mb-4 text-base font-semibold text-slate-900">{title}</h2>}{children}</section>; }
export function Badge({children, warning=false}) { return <span className={`inline-block rounded px-2 py-1 text-xs ${warning?'bg-amber-50 text-amber-800':'bg-teal-50 text-teal-800'}`}>{children}</span>; }
export function Details({payload:p}) { return <dl className="grid gap-4">{Object.entries({采集标识:p.id,纳入状态:p.status,框架协议:p.framework?'是':'否',项目名称:p.title,采购单位:p.unit,采购编号:p.code,采购方式:p.nature,物资类别:p.category,采购性质:p.subtype,采购数量:p.quantity,物资及规格:p.material,商务及资格条件:p.terms,联系方式:p.contacts,公告发布时间:p.published,报名及文件获取:p.registration,'报价 / 投标截止':p.deadline}).map(([k,v])=><div key={k} className="grid gap-1 sm:grid-cols-[130px_1fr]"><dt className="text-sm text-slate-500">{k}</dt><dd className="min-w-0 whitespace-pre-wrap break-words text-sm leading-7 text-slate-800">{v || '未披露'}</dd></div>)}</dl>; }
export function Pages({links=[]}) { return <nav aria-label="分页" className="flex flex-wrap gap-2">{links.map((l,i)=>l.url?<Link key={i} href={l.url} className={`rounded border px-3 py-2 text-sm ${l.active?'bg-teal-700 text-white':'bg-white'}`}>{l.label.replace(/&laquo;|&raquo;/g,'').replace(/pagination.previous|Previous/,'上一页').replace(/pagination.next|Next/,'下一页')}</Link>:null)}</nav>; }

export const formatTime = value => value ? new Date(value).toLocaleString('zh-CN', {timeZone:'Asia/Shanghai',hour12:false}) : '未知';
