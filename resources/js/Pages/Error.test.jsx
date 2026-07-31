// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Error from './Error';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
}));

afterEach(cleanup);

describe('safe error page', () => {
    it('renders a generic Chinese not-found message without model details', () => {
        render(<Error status={404} message="记录不存在或已被删除。" />);

        expect(screen.getByRole('heading', { name: '无法打开此记录' })).toBeInTheDocument();
        expect(screen.getByText('记录不存在或已被删除。')).toBeInTheDocument();
        expect(screen.queryByText(/App\\Models/)).not.toBeInTheDocument();
    });
});
