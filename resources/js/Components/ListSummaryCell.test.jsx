// @vitest-environment jsdom

import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ListSummaryCell from './ListSummaryCell';

describe('ListSummaryCell', () => {
    afterEach(cleanup);

    it('renders one primary value without a count badge', () => {
        render(<ListSummaryCell primary="王经理 · 13037077130" count={1} onOpen={() => {}} />);

        expect(screen.getByRole('button', { name: '王经理 · 13037077130，共 1 项' })).toBeInTheDocument();
        expect(screen.queryByText(/^\+/)).not.toBeInTheDocument();
    });

    it('renders the remaining item count for multiple values', () => {
        render(<ListSummaryCell primary="王经理 · 13037077130" count={3} onOpen={() => {}} />);

        expect(screen.getByText('+2')).toBeInTheDocument();
    });

    it('renders only the unified empty marker without an action', () => {
        render(<ListSummaryCell primary="" count={0} onOpen={() => {}} />);

        expect(screen.getByText('—')).toHaveClass('empty-value');
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('calls onOpen when the summary is clicked', () => {
        const onOpen = vi.fn();
        render(<ListSummaryCell primary="王经理" count={1} onOpen={onOpen} />);

        fireEvent.click(screen.getByRole('button', { name: '王经理，共 1 项' }));

        expect(onOpen).toHaveBeenCalledOnce();
    });
});
