// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import AnnualOutputChart from './AnnualOutputChart';
afterEach(cleanup);
it('never creates a fabricated line when yearly details are absent', () => {
    const { container } = render(<AnnualOutputChart />);
    expect(screen.getByRole('status')).toHaveTextContent('待接入逐年实际发生明细');
    expect(container.querySelector('polyline')).not.toBeInTheDocument();
});
it('sorts real annual amounts, retains zero and negative values, and does not fill missing years', () => {
    const { container } = render(<AnnualOutputChart series={[{ year: 2025, amount: 0 }, { year: 2022, amount: 120000 }, { year: 2024, amount: -10000 }, { year: 2023, amount: null }]} />);
    const points = screen.getAllByRole('button');
    expect(points.map((point) => point.getAttribute('aria-label'))).toEqual(['2022年产值 12 万元', '2024年产值 -1 万元', '2025年产值 0 万元']);
    expect(container.querySelector('polyline')).toBeInTheDocument();
    fireEvent.mouseEnter(points[0]);
    expect(screen.getByRole('tooltip')).toHaveTextContent('2022年 · 12 万元');
    fireEvent.mouseLeave(points[0]);
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
});
it('renders a single confirmed year as a point without implying a trend', () => {
    const { container } = render(<AnnualOutputChart series={[{ year: 2026, amount: 10000 }]} />);
    expect(screen.getAllByRole('button')).toHaveLength(1);
    expect(container.querySelector('polyline')).not.toBeInTheDocument();
});

it('shows coverage without claiming complete annual transaction totals', () => {
    render(<AnnualOutputChart series={[{ year: 2024, amount: 10000 }]} summary={{ covered: 1, total: 10, unknown_year: 7, ambiguous_year: 1, missing_amount: 1 }} />);
    expect(screen.getByText('按项目发生年份归集 · 1/10 项目')).toBeVisible();
    expect(screen.getByRole('img')).toHaveAttribute('aria-label', '按项目发生年份归集金额折线图');
});
