import { Link, router, usePage } from '@inertiajs/react';
import { Bell, Bot, Box, ClipboardCheck, ClipboardPlus, Database, HardHat, LayoutDashboard, LogOut, Menu, ShieldCheck, UserRound, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { businessText } from '../businessLanguage';
import { useDialogFocus } from './useDialogFocus';

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

export default function Layout({ title, eyebrow, children, aside, immersive = false, hideHeader = false }) {
    const page = usePage();
    const { auth, nav, flash, notificationUnreadCount = 0 } = page.props;
    const visibleNav = (nav || []).filter((item) => item.visible);
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const preferredMobileKeys = ['dashboard', 'notifications', 'ontology'];
    const mobilePrimary = preferredMobileKeys
        .map((key) => visibleNav.find((item) => item.key === key))
        .filter(Boolean);
    const mobileFallback = [...visibleNav]
        .filter((item) => !mobilePrimary.includes(item))
        .sort((left, right) => (left.mobile_priority || 100) - (right.mobile_priority || 100));

    mobilePrimary.push(...mobileFallback.slice(0, Math.max(0, 3 - mobilePrimary.length)));
    const mobileSecondary = visibleNav.filter((item) => !mobilePrimary.includes(item));
    const secondaryActive = mobileSecondary.some((item) => isNavItemActive(page.url, item));

    useEffect(() => {
        setMobileMenuOpen(false);
    }, [page.url]);

    useEffect(() => {
        if (!mobileMenuOpen) return undefined;

        function closeOnEscape(event) {
            if (event.key === 'Escape') setMobileMenuOpen(false);
        }

        document.addEventListener('keydown', closeOnEscape);
        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [mobileMenuOpen]);

    function logout() {
        setMobileMenuOpen(false);
        router.post('/logout');
    }

    return (
        <div className="app-shell">
            <aside className="rail desktop-rail">
                <div className="brand-lockup">
                    <span className="brand-mark"><Box size={17} /></span>
                    <span>鑫源昌智造中枢</span>
                </div>
                <nav className="nav-list">
                    {visibleNav.map((item) => {
                        const itemLabel = businessText(item.label);
                        const Icon = iconFor[item.key] || LayoutDashboard;
                        const open = isActive(page.url, item.href) || (item.children || []).some((group) => group.items.some((child) => isActive(page.url, child.href, true)));
                        const moduleGroups = (item.children || []).filter((group) => group.items?.length > 0);
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
                                {open && item.key === 'ontology' && moduleGroups.length > 0 && (
                                    <div className="nav-module-list" aria-label="业务资料模块">
                                        {moduleGroups.map((group) => {
                                            const active = group.items.some((child) => isActive(page.url, child.href, true));

                                            return (
                                                <Link
                                                    key={group.label}
                                                    href={group.items[0].href}
                                                    className={active ? 'active' : ''}
                                                    aria-current={active ? 'page' : undefined}
                                                >
                                                    {businessText(group.label)}
                                                </Link>
                                            );
                                        })}
                                    </div>
                                )}
                                {open && item.key !== 'ontology' && item.children?.length > 0 && (
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
            <MobileNavigation
                items={mobilePrimary}
                moreItems={mobileSecondary}
                currentUrl={page.url}
                notificationUnreadCount={notificationUnreadCount}
                auth={auth}
                menuOpen={mobileMenuOpen}
                moreActive={secondaryActive}
                onMenuToggle={() => setMobileMenuOpen((current) => !current)}
                onMenuClose={() => setMobileMenuOpen(false)}
                onLogout={logout}
            />
            <main className={`workspace ${immersive ? 'workspace-immersive' : ''}`}>
                {!hideHeader && (
                    <header className="workspace-head">
                        <div>
                            <p>{eyebrow}</p>
                            <h1>{title}</h1>
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

function MobileNavigation({
    items,
    moreItems,
    currentUrl,
    notificationUnreadCount,
    auth,
    menuOpen,
    moreActive,
    onMenuToggle,
    onMenuClose,
    onLogout,
}) {
    const panelRef = useRef(null);
    useDialogFocus(menuOpen, panelRef);

    return (
        <>
            {menuOpen && (
                <div className="mobile-more-backdrop" onPointerDown={onMenuClose}>
                    <section
                        ref={panelRef}
                        className="mobile-more-panel"
                        role="dialog"
                        aria-modal="true"
                        aria-label="更多业务入口"
                        tabIndex={-1}
                        onPointerDown={(event) => event.stopPropagation()}
                    >
                        <header>
                            <div>
                                <strong>更多业务入口</strong>
                                <span>{auth.user?.name || '当前用户'}</span>
                            </div>
                            <button type="button" className="icon-link" aria-label="关闭更多业务入口" onClick={onMenuClose}>
                                <X size={17} />
                            </button>
                        </header>
                        <nav aria-label="移动端更多导航">
                            {moreItems.map((item) => {
                                const Icon = iconFor[item.key] || LayoutDashboard;
                                const active = isNavItemActive(currentUrl, item);

                                return (
                                    <Link key={item.href} href={item.href} className={active ? 'active' : ''} aria-current={active ? 'page' : undefined}>
                                        <Icon size={18} />
                                        <span>{businessText(item.label)}</span>
                                        {item.key === 'notifications' && notificationUnreadCount > 0 && (
                                            <b>{notificationUnreadCount > 99 ? '99+' : notificationUnreadCount}</b>
                                        )}
                                    </Link>
                                );
                            })}
                        </nav>
                        <button type="button" className="mobile-more-logout" onClick={onLogout}>
                            <LogOut size={17} /> 退出
                        </button>
                    </section>
                </div>
            )}
            <nav
                className="mobile-nav"
                aria-label="移动端主导航"
                style={{ '--mobile-nav-count': items.length + 1 }}
            >
                {items.map((item) => {
                    const itemLabel = businessText(item.label);
                    const Icon = iconFor[item.key] || LayoutDashboard;
                    const active = isNavItemActive(currentUrl, item);

                    return (
                        <Link key={item.href} href={item.href} className={active ? 'active' : ''} aria-current={active ? 'page' : undefined}>
                            <Icon size={19} />
                            <span>{itemLabel}</span>
                            {item.key === 'notifications' && notificationUnreadCount > 0 && (
                                <b aria-label={`${notificationUnreadCount} 条未读通知`}>
                                    {notificationUnreadCount > 99 ? '99+' : notificationUnreadCount}
                                </b>
                            )}
                        </Link>
                    );
                })}
                <button
                    type="button"
                    className={moreActive || menuOpen ? 'active' : ''}
                    aria-label="更多业务入口"
                    aria-haspopup="dialog"
                    aria-expanded={menuOpen}
                    onClick={onMenuToggle}
                >
                    <Menu size={19} />
                    <span>更多</span>
                </button>
            </nav>
        </>
    );
}

function isNavItemActive(currentUrl, item) {
    return isActive(currentUrl, item.href)
        || (item.children || []).some((group) => group.items.some((child) => isActive(currentUrl, child.href, true)));
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
