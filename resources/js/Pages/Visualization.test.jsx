// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Visualization from './Visualization';
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({ children, ...props }) => <a {...props}>{children}</a> }));
vi.mock('../Components/Layout', () => ({ default: ({ children }) => <main>{children}</main> }));
afterEach(cleanup);
const total = { value: 100000, coverage: '1/1', abnormal: 0 };
const project = { id: 'p1', name: '真实项目', code: 'XYC-001', occurred: 100000, paid: 70000, unpaid: 40000 };
const data = {
    scope: '公司全量', as_of: '2026-10-07 12:00', details_url: '/objects/project',
    collection: { occurred: 100000, paid: 70000, remaining: 30000, ratio: 70, chartable: true, coverage: '1/1' },
    projects: {
        totals: { occurred: total, paid: { ...total, value: 70000 }, unpaid: { ...total, value: 40000 } },
        salespeople: [{ name: '业务员甲', occurred: 100000, paid: 70000, unpaid: 40000 }],
        top_unpaid: [project], stages: [{ name: '生产加工', value: 1 }], active_count: 1,
        inactive: [{ name: '已完成', value: 2 }], aging: [{ ...project, days: 200 }], aging_excluded: 1,
        aging_basis: '仅统计已维护有效最后回款日期的欠款项目',
    },
};
describe('approved visualization page', () => {
    it('shows real sector values on hover and clears the dynamic highlight on exit', () => {
        render(<Visualization visualization={data} />);
        const ring = screen.getByRole('img', { name: /公司回款情况/ });
        const paid = ring.querySelector('.viz-sector');
        fireEvent.mouseEnter(paid);
        expect(screen.getByRole('tooltip')).toHaveTextContent('已回款');
        expect(screen.getByRole('tooltip')).toHaveTextContent('7 万元');
        expect(screen.getByRole('tooltip')).toHaveTextContent('70.0%');
        expect(paid).toHaveAttribute('stroke-width', '40');
        fireEvent.mouseLeave(ring);
        expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
        expect(paid).toHaveAttribute('stroke-width', '34');
    });
    it('keeps independent financial sources, real project amounts and only the approved table', () => {
        render(<Visualization visualization={data} />);
        expect(screen.queryByRole('heading', { name: '可视化大盘' })).not.toBeInTheDocument();
        expect(within(screen.getByRole('heading', { name: '公司回款比例' }).closest('section')).getByRole('img', { name: /公司回款情况/ })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: '欠款项目回款情况' })).toBeInTheDocument();
        expect(screen.getByLabelText('真实项目 已发生 10 万元')).toBeInTheDocument();
        expect(screen.getByLabelText('真实项目 已回款 7 万元')).toBeInTheDocument();
        expect(screen.getByLabelText('真实项目 欠款 4 万元')).toBeInTheDocument();
        expect(screen.getAllByRole('table')).toHaveLength(1);
        expect(within(screen.getByRole('table')).getByText('200 天')).toBeInTheDocument();
        const aging = screen.getByRole('heading', { name: '项目未回款时间排行' }).closest('section');
        expect(aging.querySelectorAll('.viz-subtitle, .viz-footnote')).toHaveLength(0);
        expect(screen.queryByText(/1 个欠款项目因日期缺失或无效未纳入/)).not.toBeInTheDocument();
        expect(screen.queryByText('按未回款时长降序')).not.toBeInTheDocument();
        expect(aging.querySelector('.viz-aging-scroll')).toContainElement(screen.getByRole('table'));
        expect(screen.getByRole('link', { name: '返回经营大盘' })).toHaveAttribute('href', '/dashboard');
        expect(screen.queryByRole('link', { name: /查看业务明细/ })).not.toBeInTheDocument();
        const finance = screen.getByRole('heading', { name: '项目主档金额汇总' }).closest('section');
        expect(finance.querySelectorAll('.viz-footnote')).toHaveLength(0);
        const ratio = screen.getByRole('heading', { name: '公司回款比例' }).closest('section');
        expect(ratio).toHaveTextContent('同源已回款');
        expect(ratio).toHaveTextContent('尚未回款');
        expect(ratio).toHaveTextContent('70.0%');
        expect(finance.querySelector('.viz-ring')).not.toBeInTheDocument();
        expect(screen.queryByText('业务员欠款排行')).not.toBeInTheDocument();
        expect(screen.getByText('已完成')).toBeInTheDocument();
    });
    it('refits desktop card space on window resize without changing card contents', () => {
        const { container } = render(<Visualization visualization={data} />);
        const page = container.querySelector('.visualization-page');
        const initial = page.style.getPropertyValue('--viz-screen-height');
        const height = window.innerHeight;
        Object.defineProperty(window, 'innerHeight', { configurable: true, value: 900 });
        fireEvent(window, new Event('resize'));
        expect(page.style.getPropertyValue('--viz-screen-height')).toBe('884px');
        expect(page.style.getPropertyValue('--viz-screen-height')).not.toBe(initial);
        expect(screen.getAllByRole('heading')).toHaveLength(8);
        Object.defineProperty(window, 'innerHeight', { configurable: true, value: height });
    });
    it('shows only the first ten aging projects without hiding other project cards', () => {
        const aging = Array.from({ length: 12 }, (_, index) => ({ ...project, id: `aging-${index}`, name: `排行项目${index + 1}`, days: 1000 - index }));
        render(<Visualization visualization={{ ...data, projects: { ...data.projects, aging } }} />);
        expect(within(screen.getByRole('table')).getAllByRole('row')).toHaveLength(11);
        expect(screen.getByRole('link', { name: '排行项目10', exact: true })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: '排行项目11', exact: true })).not.toBeInTheDocument();
        expect(screen.getByRole('heading', { name: '项目阶段分布' })).toBeInTheDocument();
    });
    it('places project cards before salesperson cards while preserving both groups and internal order', () => {
        render(<Visualization visualization={data} />);
        expect(screen.getAllByRole('heading').map((heading) => heading.textContent)).toEqual([
            '公司回款比例', '项目主档金额汇总', '欠款项目回款情况', '项目未回款时间排行', '项目阶段分布',
            '业务员已发生金额占比', '业务员欠款金额占比', '业务员已回款金额占比',
        ]);
        expect(screen.getByRole('link', { name: /真实项目\s*XYC-001/ })).toHaveAttribute('href', '/objects/project?record=p1&mode=detail');
        expect(screen.getAllByText('业务员甲')).toHaveLength(3);
    });
    it('preserves actual overpayment percentages and omits sectors without inventing zero', () => {
        render(<Visualization visualization={{ ...data, projects: null, collection: { ...data.collection, paid: 150000, remaining: -50000, ratio: 150, chartable: false } }} />);
        expect(screen.getByText('150.0%')).toBeInTheDocument();
        expect(screen.getByText('金额异常，保留实际比例')).toBeInTheDocument();
        expect(screen.getByRole('img').querySelectorAll('circle')).toHaveLength(1);
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });
    it('draws positive salesperson net totals while disclosing negative source records', () => {
        render(<Visualization visualization={{ ...data, projects: { ...data.projects, totals: { ...data.projects.totals, unpaid: { ...data.projects.totals.unpaid, abnormal: 1 } } } }} />);
        const chart = screen.getByRole('img', { name: /业务员欠款金额占比/ });
        expect(chart.querySelectorAll('circle')).toHaveLength(2);
        expect(screen.getByText(/1 项负金额记录/)).toBeInTheDocument();
    });
    it('renders a safe empty scope without hidden source data', () => {
        render(<Visualization visualization={{ ...data, projects: null, collection: null, details_url: null }} />);
        expect(screen.getByText(/暂无可查看的数据/)).toBeInTheDocument();
        expect(screen.queryByRole('img')).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /查看业务明细/ })).not.toBeInTheDocument();
    });
    it('distinguishes zero, missing values and exposes all salesperson labels', () => {
        const salespeople = Array.from({ length: 20 }, (_, i) => ({ name: `业务员${i}`, occurred: i === 0 ? null : 0, paid: 0, unpaid: 0 }));
        const zero = { value: 0, coverage: '19/20', abnormal: 0 };
        render(<Visualization visualization={{ ...data, projects: { ...data.projects, salespeople, totals: { occurred: zero, paid: zero, unpaid: zero }, top_unpaid: [], aging: [], stages: [], active_count: 0 }, collection: { ...data.collection, paid: 0, remaining: 100000, ratio: 0 } }} />);
        expect(screen.getByText('0.0%')).toBeInTheDocument();
        expect(screen.getAllByText('业务员19')).toHaveLength(3);
        expect(screen.getAllByText('业务员0')).toHaveLength(3);
        expect(screen.getByText('暂无有效欠款项目')).toBeInTheDocument();
        expect(screen.getByText('暂无可排行的欠款项目')).toBeInTheDocument();
        expect(screen.getAllByText('暂无可计算数据').length).toBeGreaterThan(0);
    });
    it('keeps stage labels, counts and inactive statuses inside the centered stage card', () => {
        render(<Visualization visualization={data} />);
        const card = screen.getByRole('heading', { name: '项目阶段分布' }).closest('section');
        expect(within(card).getByRole('img', { name: '项目阶段分布：1 活跃项目' })).toBeVisible();
        expect(within(card).getByText('生产加工')).toBeVisible();
        expect(within(card).getByText('100.0%')).toBeVisible();
        expect(within(card).getByText('已完成')).toBeVisible();
        expect(screen.getByRole('heading', { name: '业务员已发生金额占比' })).toBeVisible();
    });
    it('removes annual output while preserving stage and financial charts', () => {
        render(<Visualization visualization={{ ...data, projects: { ...data.projects, annual_output: [{ year: 2023, amount: 4469025.95 }, { year: 2024, amount: 213478.6 }], annual_output_summary: { covered: 2, total: 337, unknown_year: 334, ambiguous_year: 1, missing_amount: 0 } } }} />);
        const card = screen.getByRole('heading', { name: '项目阶段分布' }).closest('section');
        expect(within(card).queryByRole('img', { name: /按项目发生年份/ })).not.toBeInTheDocument();
        expect(within(card).getByRole('img', { name: /项目阶段分布/ })).toBeInTheDocument();
        expect(within(card).queryByText('按项目发生年份归集 · 2/337 项目')).not.toBeInTheDocument();
        expect(screen.getByRole('heading', { name: '项目主档金额汇总' })).toBeVisible();
        expect(screen.queryByText('待接入逐年实际发生明细')).not.toBeInTheDocument();
    });
    it('removes the entire toolbar while preserving financial and project navigation', () => {
        const { container } = render(<Visualization visualization={data} />);
        expect(container.querySelector('.viz-toolbar')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: '全屏展示' })).not.toBeInTheDocument();
        expect(screen.queryByText('公司全量')).not.toBeInTheDocument();
        expect(screen.getByRole('heading', { name: '项目主档金额汇总' })).toBeVisible();
        expect(screen.getByRole('link', { name: '真实项目', exact: true })).toHaveAttribute('href', '/objects/project?record=p1&mode=detail');
    });

    it('retains visible charts when browser fullscreen changes without restoring toolbar buttons', () => {
        render(<Visualization visualization={data} />);
        fireEvent(document, new Event('fullscreenchange'));
        expect(screen.queryByRole('button', { name: /全屏/ })).not.toBeInTheDocument();
        expect(screen.getByRole('heading', { name: '项目阶段分布' })).toBeVisible();
    });

});
