import { Link, router, usePage } from '@inertiajs/react';
import { Bot, Box, ClipboardCheck, ClipboardPlus, Database, LayoutDashboard, LogOut, ShieldCheck, UserRound } from 'lucide-react';

const iconFor = {
    '经营大盘': LayoutDashboard,
    '提交采购申请': ClipboardPlus,
    '采购OA审批': ClipboardCheck,
    '领料审批': ClipboardCheck,
    '本体工作台': Database,
    '用户与权限': ShieldCheck,
    'AI 数据助手': Bot,
};

export default function Layout({ title, eyebrow, children, aside }) {
    const page = usePage();
    const { auth, nav, flash } = page.props;
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
                        const Icon = iconFor[item.label] || LayoutDashboard;
                        const open = isActive(page.url, item.href) || (item.children || []).some((group) => group.items.some((child) => isActive(page.url, child.href, true)));
                        return (
                            <div key={item.href} className="nav-group">
                                <Link href={item.href} className={`nav-item ${open ? 'active' : ''}`}>
                                    <Icon size={17} />
                                    <span>{item.label}</span>
                                </Link>
                                {open && item.children?.length > 0 && (
                                    <div className="nav-sublist">
                                        {item.children.map((group) => (
                                            <section key={group.label}>
                                                <h4>{group.label}</h4>
                                                {group.items.map((child) => (
                                                    <Link key={child.href} href={child.href} className={isActive(page.url, child.href, true) ? 'active' : ''}>
                                                        {child.label}
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
            <main className="workspace">
                <header className="workspace-head">
                    <div>
                        <p>{eyebrow}</p>
                        <h1>{title}</h1>
                    </div>
                    <div className="role-strip">
                        {(auth.roles || []).map((role) => <span key={role.id}>{role.label}</span>)}
                    </div>
                </header>
                {flash?.status && <div className="notice">{flash.status}</div>}
                <div className={aside ? 'workspace-grid' : ''}>
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
