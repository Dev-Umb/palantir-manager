// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import NotificationsIndex from './Index';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
    router: { patch: vi.fn() },
}));

vi.mock('../../Components/Layout', () => ({
    default: ({ children, title, eyebrow }) => <main data-title={title} data-eyebrow={eyebrow}>{children}</main>,
}));

afterEach(cleanup);

describe('notification list layout', () => {
    it('uses the unified page header contract', () => {
        const { container } = render(
            <NotificationsIndex notifications={{ data: [], links: [] }} unreadCount={0} />,
        );

        expect(container.querySelector('main')).toHaveAttribute('data-title', '通知中心');
        expect(container.querySelector('main')).toHaveAttribute('data-eyebrow', '通知中心');
    });
});
