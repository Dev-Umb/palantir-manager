// @vitest-environment jsdom
import { render, cleanup } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import Layout from '../../Components/Layout';
vi.mock('@inertiajs/react', () => ({
 Link: ({ children, href, ...props }) => <a href={href} {...props}>{children}</a>, router: { post: vi.fn() },
 usePage: () => ({ url: '/procurement-hub/admin', props: { auth: { user: { name: '管理员' }, roles: [] }, nav: [
  {key:'hub',label:'招采信息中心',href:'/procurement-hub',exact:true,visible:true},
  {key:'hub-admin',label:'来源管理',href:'/procurement-hub/admin',visible:true},
 ], flash: {} } }),
}));
afterEach(cleanup);
it('marks the current hub page without marking the root announcement list', () => {
 const { container } = render(<Layout title="来源管理">内容</Layout>);
 expect(container.querySelector('.desktop-rail a[href="/procurement-hub"]').classList.contains('active')).toBe(false);
 expect(container.querySelector('.desktop-rail a[href="/procurement-hub/admin"]').classList.contains('active')).toBe(true);
});
