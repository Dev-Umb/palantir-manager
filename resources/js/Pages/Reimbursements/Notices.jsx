import { Head, Link } from '@inertiajs/react';
import Layout from '../../Components/Layout';
import { Pages } from './shared';
export default function Notices({ notices }) { return <Layout hideHeader><Head title="报销通知" /><main className="oa oa-detail"><Link href="/reimbursements">← 报销审批</Link><h1>报销通知</h1>{notices.data.map((n) => <Link className="oa-panel oa-notice" key={n.id} href={n.url}><strong>{!n.read_at && '● '}{n.message}</strong><small>{new Date(n.created_at).toLocaleString('zh-CN')} · {n.read_at ? '已读' : '未读'}</small></Link>)}{!notices.data.length && <p>暂无报销通知</p>}<Pages data={notices} /></main></Layout>; }
