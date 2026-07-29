import { Head, Link, router } from '@inertiajs/react';
import { Bell, Check, CheckCheck, ExternalLink } from 'lucide-react';
import Layout from '../../Components/Layout';

export default function NotificationsIndex({ notifications, unreadCount }) {
    const items = notifications?.data || [];

    return (
        <Layout title="通知中心" eyebrow="项目风险提醒">
            <Head title="通知中心" />
            <section className="surface">
                <div className="section-head">
                    <div>
                        <p>合同与回款</p>
                        <h2>站内通知</h2>
                        <span>当前有 {unreadCount} 条未读风险提醒，已处理风险仍保留历史记录。</span>
                    </div>
                    {unreadCount > 0 && (
                        <button className="ghost-button" type="button" onClick={() => router.patch('/notifications/read-all')}>
                            <CheckCheck size={16} /> 全部已读
                        </button>
                    )}
                </div>

                {items.length ? (
                    <div className="table-scroll">
                        <table className="data-table">
                            <thead>
                                <tr><th>状态</th><th>类型</th><th>项目</th><th>提醒内容</th><th>触发时间</th><th>操作</th></tr>
                            </thead>
                            <tbody>
                                {items.map((notification) => (
                                    <tr key={notification.id}>
                                        <td>
                                            <span className="pill">
                                                {notification.status === 'resolved' ? '已处理' : notification.read_at ? '已读' : '未读'}
                                            </span>
                                        </td>
                                        <td>{notification.type_label}</td>
                                        <td>
                                            <strong>{notification.project?.project_no || '-'}</strong>
                                            <div className="muted">{notification.project?.name || '项目已删除'}</div>
                                        </td>
                                        <td>{notification.message}</td>
                                        <td>{formatDate(notification.triggered_at)}</td>
                                        <td>
                                            {!notification.read_at && (
                                                <button className="ghost-button" type="button" onClick={() => router.patch(`/notifications/${notification.id}/read`)}>
                                                    <Check size={15} /> 标为已读
                                                </button>
                                            )}
                                            {notification.project_url && (
                                                <Link className="small-action" href={notification.project_url}>
                                                    查看项目 <ExternalLink size={14} />
                                                </Link>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="empty-state">
                        <Bell size={24} />
                        <strong>目前没有需要处理的风险提醒</strong>
                        <span>合同逾期、回款异常等风险出现时，会在这里通知你；已处理记录也会保留。</span>
                        <Link className="secondary-button" href="/">返回经营大盘</Link>
                    </div>
                )}

                {notifications?.links?.length > 3 && (
                    <nav className="pagination" aria-label="通知分页">
                        {notifications.links.map((link, index) => link.url ? (
                            <Link key={`${link.label}-${index}`} className={link.active ? 'active' : ''} href={link.url} preserveScroll>
                                <span dangerouslySetInnerHTML={{ __html: link.label }} />
                            </Link>
                        ) : (
                            <span key={`${link.label}-${index}`} dangerouslySetInnerHTML={{ __html: link.label }} />
                        ))}
                    </nav>
                )}
            </section>
        </Layout>
    );
}

function formatDate(value) {
    if (!value) return '-';

    return new Intl.DateTimeFormat('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}
