import { Link, usePage } from '@inertiajs/react';

export default function SteelGirderNavigation() {
    const { props, url } = usePage();
    const items = (props.nav || []).find((item) => item.key === 'support-library')?.children?.flatMap((group) => group.items) || [];
    return <nav aria-label="钢箱梁工作台" style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 4, width: 'fit-content', maxWidth: '100%', padding: 4, marginBottom: 16, border: '1px solid var(--line)', borderRadius: 12, background: '#e9eef3' }}>
        {items.map((item) => {
            const active = url.split('?')[0] === new URL(item.href, 'https://local.invalid').pathname;
            return <Link key={item.href} href={item.href} style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', boxSizing: 'border-box', minHeight: 40, padding: '8px 16px', border: '1px solid transparent', borderRadius: 8, fontSize: 14, fontWeight: 600, lineHeight: '22px', whiteSpace: 'nowrap', color: active ? '#fff' : '#526174', background: active ? '#286b91' : 'transparent' }} aria-current={active ? 'page' : undefined}>{item.label}</Link>;
        })}
    </nav>;
}
