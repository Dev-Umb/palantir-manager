// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Dashboard from './Dashboard';

const usePollMock = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
    usePoll: usePollMock,
}));

vi.mock('../Components/Layout', () => ({ default: ({ children }) => <main>{children}</main> }));

vi.mock('recharts', () => ({
    ResponsiveContainer: ({ children }) => <div>{children}</div>,
    PieChart: ({ children }) => <div>{children}</div>,
    Pie: ({ children }) => <div>{children}</div>,
    Cell: () => null,
    Tooltip: () => null,
    LineChart: ({ children }) => <div>{children}</div>,
    CartesianGrid: () => null,
    XAxis: () => null,
    YAxis: () => null,
    Line: () => null,
}));

afterEach(cleanup);

const cockpit = {
    meta: { scope: '全公司授权范围', as_of: '2026-08-04T10:30:00+08:00' },
    kpis: [
        { key: 'occurred_amount', label: '当前公司产值', value: 4200000, format: 'currency', hint: '合同已发生金额', coverage: { valid: 3, total: 4 } },
        { key: 'collection_rate', label: '回款率', value: 69, format: 'percentage', hint: '已付 / 已发生', coverage: { valid: 3, total: 4 } },
        { key: 'tender_win_rate', label: '中标率', value: 37.5, format: 'percentage', hint: '已中标 / 已决标', coverage: { valid: 8, total: 9 } },
        { key: 'current_debt', label: '当前欠款', value: 1300000, format: 'currency', hint: '已发生 - 已付', coverage: { valid: 3, total: 4 } },
    ],
    panels: {
        cash_flow: {
            url: '/objects/project',
            series: [
                { key: 'contracted', label: '合同金额', value: 5000000, coverage: { valid: 4, total: 4 } },
                { key: 'occurred', label: '已发生', value: 4200000, coverage: { valid: 3, total: 4 } },
                { key: 'reconciled', label: '已结算', value: 3500000, coverage: { valid: 3, total: 4 } },
                { key: 'invoiced', label: '已开票', value: 3200000, coverage: { valid: 3, total: 3 } },
                { key: 'paid', label: '已回款', value: 2900000, coverage: { valid: 3, total: 4 } },
            ],
        },
        tender_pipeline: {
            url: '/objects/tender', records_count: 9, budget_total: 8000000,
            budget_coverage: { valid: 8, total: 9 },
            statuses: [{ status: '线索', count: 1 }, { status: '已递交', count: 2 }, { status: '已中标', count: 3 }, { status: '未中标', count: 2 }, { status: '已放弃', count: 1 }],
        },
        project_status: {
            url: '/objects/project', records_count: 8, active_total: 6, completed_count: 1, unmaintained_count: 1,
            statuses: [
                { status: '投标中', count: 1, percentage: 16.7 },
                { status: '已中标', count: 2, percentage: 33.3 },
                { status: '已拿到加工函', count: 2, percentage: 33.3 },
                { status: '合同签署', count: 1, percentage: 16.7 },
            ],
        },
        production_delivery: {
            production: { url: null, total_ton: 680, planned_ton: 760, coverage: { valid: 5, total: 6 }, statuses: [{ status: '待开始', count: 1 }, { status: '生产中', count: 2 }, { status: '暂停', count: 0 }, { status: '已完成', count: 2 }] },
            shipment: { url: null, total_ton: 510, trend_coverage: { valid: 4, total: 6 }, undated_ton: 12, invalid_quantity_count: 1, monthly: [{ month: '2026-07', label: '07月', ton: 220 }, { month: '2026-08', label: '08月', ton: 278 }] },
        },
        project_amounts: {
            url: '/objects/project', projects_count: 5, unassigned_projects_count: 1, as_of: '2026-08-15T10:30:20+08:00',
            company: [
                { key: 'occurred_amount', label: '已发生金额总计', value: 4200000, coverage: { valid: 4, total: 5 } },
                { key: 'paid_amount', label: '已回款金额总计', value: 2900000, coverage: { valid: 3, total: 5 } },
                { key: 'unpaid_amount', label: '未回款金额总计', value: 1300000, coverage: { valid: 3, total: 5 } },
            ],
            salespeople: [{
                user_id: 7, name: '业务员甲', projects_count: 4,
                amounts: [
                    { key: 'occurred_amount', label: '已发生金额总计', value: 4200000, coverage: { valid: 4, total: 4 } },
                    { key: 'paid_amount', label: '已回款金额总计', value: 2900000, coverage: { valid: 3, total: 4 } },
                    { key: 'unpaid_amount', label: '未回款金额总计', value: null, coverage: { valid: 0, total: 4 } },
                ],
            }],
        },
    },
    project_progress: {
        url: '/objects/project?record=project-1&mode=detail', project_no: 'XYC-001', project_name: '智能物流中心',
        steps: [{ label: '投标中', state: 'done' }, { label: '已中标', state: 'done' }, { label: '已拿到加工函', state: 'current' }, { label: '合同签署', state: 'upcoming' }, { label: '已完成', state: 'upcoming' }],
    },
    project_progresses: [
        {
            project_id: 'project-1', url: '/objects/project?record=project-1&mode=detail', project_no: 'XYC-001', project_name: '智能物流中心',
            steps: [{ label: '投标中', state: 'done' }, { label: '已中标', state: 'done' }, { label: '已拿到加工函', state: 'current' }, { label: '合同签署', state: 'upcoming' }, { label: '已完成', state: 'upcoming' }],
        },
        {
            project_id: 'project-2', url: '/objects/project?record=project-2&mode=detail', project_no: 'XYC-002', project_name: '高位仓库扩建',
            steps: [{ label: '投标中', state: 'done' }, { label: '已中标', state: 'current' }, { label: '已拿到加工函', state: 'upcoming' }, { label: '合同签署', state: 'upcoming' }, { label: '已完成', state: 'upcoming' }],
        },
    ],
};

describe('Company operations cockpit', () => {
    it('renders traceable KPIs, line and pie charts, actual object statuses and reminders', () => {
        const { container } = render(<Dashboard
            cockpit={cockpit}
            recentProjects={[{
                id: 'project-1', code: 'XYC-001', title: '智能物流中心',
                payload: { project_no: 'XYC-001', overall_status: '已拿到加工函', contract_status: '部分签署', payment_status: '部分回款' },
                display: { business_owner_user_id: '业务员甲' },
            }]}
            notificationRisks={[{ id: 1, type: 'payment', type_label: '回款提醒', project_no: 'XYC-001', project_name: '智能物流中心', project_url: '/objects/project?record=project-1&mode=detail', read: false }]}
        />);

        expect(screen.getByRole('heading', { name: '公司经营驾驶舱' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: '公司与业务员金额汇总' })).toBeInTheDocument();
        expect(screen.getByLabelText('公司项目金额总计')).toHaveTextContent('4,200,000.00 元');
        expect(screen.getAllByRole('cell', { name: '业务员甲' })).toHaveLength(2);
        expect(screen.getByRole('cell', { name: /4 个/ })).toBeInTheDocument();
        expect(screen.getByText(/1 条未分配或账号无效/)).toHaveTextContent('每 15 秒自动刷新');
        expect(screen.getByRole('link', { name: /查看项目主表/ })).toHaveAttribute('href', '/objects/project');
        expect(screen.getAllByText('—')).not.toHaveLength(0);
        expect(screen.getAllByText('420.0')).not.toHaveLength(0);
        expect(screen.getByText('69.0')).toBeInTheDocument();
        expect(screen.getByText('37.5')).toBeInTheDocument();
        expect(screen.getByText('130.0')).toBeInTheDocument();
        expect(screen.getByText('合同金额')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: '当前招投标管线' })).toBeInTheDocument();
        expect(screen.getByRole('img', { name: /活跃项目共6个/ })).toBeInTheDocument();
        expect(screen.getByRole('img', { name: /月度发货吨位：07月220吨，08月278吨/ })).toBeInTheDocument();
        expect(screen.getByText(/趋势覆盖 4\/6/)).toHaveTextContent('12 吨缺日期只计累计值');
        expect(screen.getByText(/趋势覆盖 4\/6/)).toHaveTextContent('1 条吨位异常未计入');
        expect(screen.getAllByText('已拿到加工函')).toHaveLength(3);
        expect(screen.getByText('部分签署')).toBeInTheDocument();
        expect(screen.getAllByText('业务员甲')).toHaveLength(2);
        expect(screen.getByText('回款提醒')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /进入项目表/ })).toHaveAttribute('href', '/objects/project');
        expect(screen.getByRole('link', { name: /查看明细/ })).toHaveAttribute('href', '/objects/project');
        expect(screen.queryByText('项目当前走到哪一步')).not.toBeInTheDocument();
        expect(screen.getByLabelText('切换项目')).toHaveValue('project-1');
        expect(screen.getByLabelText('查看智能物流中心项目详情')).toHaveAttribute('href', '/objects/project?record=project-1&mode=detail');
        expect(container.querySelector('.cockpit-progress-step.current span')).toHaveTextContent('已拿到加工函');

        fireEvent.change(screen.getByLabelText('切换项目'), { target: { value: 'project-2' } });

        expect(screen.getByLabelText('查看高位仓库扩建项目详情')).toHaveAttribute('href', '/objects/project?record=project-2&mode=detail');
        expect(container.querySelector('.cockpit-progress-step.current span')).toHaveTextContent('已中标');
        expect(screen.queryByText('演示数据')).not.toBeInTheDocument();
        expect(screen.queryByText('进行中')).not.toBeInTheDocument();
        expect(screen.queryByText('预警')).not.toBeInTheDocument();
        expect(usePollMock).toHaveBeenCalledWith(15000, { only: ['cockpit'] }, { mode: 'rest' });
    });

    it('shows a safe empty state without inventing zero KPIs', () => {
        render(<Dashboard cockpit={{ meta: { scope: '我的可见范围', as_of: null }, kpis: [], panels: {} }} />);

        expect(screen.getByText('暂无可展示的经营数据')).toBeInTheDocument();
        expect(screen.queryByText('0.0%')).not.toBeInTheDocument();
        expect(screen.queryByText('0.0万元')).not.toBeInTheDocument();
    });

    it('renders an em dash for unavailable KPI values', () => {
        render(<Dashboard cockpit={{ meta: {}, kpis: [{ key: 'collection_rate', label: '回款率', value: null, format: 'percentage', hint: '无有效分母', coverage: { valid: 0, total: 2 } }], panels: {} }} />);

        expect(screen.getByText('—')).toBeInTheDocument();
        expect(screen.queryByText('0.0')).not.toBeInTheDocument();
    });
});
