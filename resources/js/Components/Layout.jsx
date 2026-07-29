import { Link, router, usePage } from '@inertiajs/react';
import { Bell, Bot, Box, ClipboardCheck, ClipboardPlus, Database, HardHat, LayoutDashboard, LogOut, ShieldCheck, UserRound } from 'lucide-react';
import { businessText } from '../businessLanguage';

const iconFor = {
    dashboard: LayoutDashboard,
    notifications: Bell,
    'requisition-create': ClipboardPlus,
    approvals: ClipboardCheck,
    'team-log': HardHat,
    ontology: Database,
    rbac: ShieldCheck,
    ai: Bot,
};

export default function Layout({ title, eyebrow, children, aside, immersive = false }) {
    const page = usePage();
    const { auth, nav, flash, notificationUnreadCount = 0 } = page.props;
    const visibleNav = (nav || []).filter((item) => item.visible);

    function logout() {
        router.post('/logout');
    }

    return (
        <div className="app-shell">
            <aside className="rail">
                <div className="brand-lockup">
                    <span className="brand-mark"><Box size={17} /></span>
                    <span>鑫源昌智造中枢</span>
                </div>
                <nav className="nav-list">
                    {visibleNav.map((item) => {
                        const itemLabel = businessText(item.label);
                        const Icon = iconFor[item.key] || LayoutDashboard;
                        const open = isActive(page.url, item.href) || (item.children || []).some((group) => group.items.some((child) => isActive(page.url, child.href, true)));
                        return (
                            <div key={item.href} className="nav-group">
                                <Link href={item.href} className={`nav-item ${open ? 'active' : ''}`}>
                                    <Icon size={17} />
                                    <span>{itemLabel}</span>
                                    {item.key === 'notifications' && notificationUnreadCount > 0 && (
                                        <b className="nav-badge" aria-label={`${notificationUnreadCount} 条未读通知`}>
                                            {notificationUnreadCount > 99 ? '99+' : notificationUnreadCount}
                                        </b>
                                    )}
                                </Link>
                                {open && item.children?.length > 0 && (
                                    <div className="nav-sublist">
                                        {item.children.map((group) => (
                                            <section key={group.label}>
                                                <h4>{businessText(group.label)}</h4>
                                                {group.items.map((child) => (
                                                    <Link key={child.href} href={child.href} className={isActive(page.url, child.href, true) ? 'active' : ''}>
                                                        <span>{businessText(child.label)}</span>
                                                        {child.new_task_count > 0 && (
                                                            <b
                                                                className="nav-child-badge"
                                                                aria-label={`${businessText(child.label)} ${child.new_task_count} 个新任务`}
                                                            >
                                                                {child.new_task_count > 99 ? '99+' : child.new_task_count}
                                                            </b>
                                                        )}
                                                    </Link>
                                                ))}
                                            </section>
                                        ))}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </nav>
                <div className="rail-footer">
                    <div className="user-chip">
                        <UserRound size={16} />
                        <div>
                            <strong>{auth.user?.name}</strong>
                            <small>{(auth.roles || []).map((role) => role.label).join('、') || '未分配角色'}</small>
                        </div>
                    </div>
                    <button className="ghost-button" type="button" onClick={logout}>
                        <LogOut size={15} /> 退出
                    </button>
                </div>
            </aside>
            <main className={`workspace ${immersive ? 'workspace-immersive' : ''}`}>
                {!immersive && (
                    <header className="workspace-head">
                        <div>
                            <p>{eyebrow}</p>
                            <h1>{title}</h1>
                        </div>
                        <div className="role-strip">
                            {(auth.roles || []).map((role) => (
                                <span key={role.id}>{role.label === '管理' ? '管理员视图' : `${role.label}视图`}</span>
                            ))}
                        </div>
                    </header>
                )}
                {flash?.status && <div className="notice">{flash.status}</div>}
                <div className={`workspace-content ${aside ? 'workspace-grid' : ''}`}>
                    <section>{children}</section>
                    {aside && <aside className="side-panel">{aside}</aside>}
                </div>
            </main>
        </div>
    );
}

function isActive(currentUrl, href, exact = false) {
    const current = (currentUrl || '').split('?')[0];
    const path = pathOf(href);

    return exact ? current === path : current === path || current.startsWith(`${path}/`);
}

function pathOf(href) {
    try {
        return new URL(href, window.location.origin).pathname;
    } catch {
        return href;
    }
}
