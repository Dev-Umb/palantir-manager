import { Link, usePage } from '@inertiajs/react';

export default function SteelGirderNavigation() {
    const { props, url } = usePage();
    const items = (props.nav || []).find((item) => item.key === 'support-library')?.children?.flatMap((group) => group.items) || [];
    return <nav aria-label="钢箱梁工作台" style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginBottom: 12 }}>
        {items.map((item) => {
            const active = url.split('?')[0] === new URL(item.href, 'https://local.invalid').pathname;
            return <Link key={item.href} href={item.href} className={active ? 'primary-button' : 'secondary-button'} aria-current={active ? 'page' : undefined}>{item.label}</Link>;
        })}
    </nav>;
}
