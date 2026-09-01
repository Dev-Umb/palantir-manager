// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
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

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('notification list layout', () => {
    it('uses the unified page header contract', () => {
        const { container } = render(
            <NotificationsIndex notifications={{ data: [], links: [] }} unreadCount={0} />,
        );

        expect(container.querySelector('main')).toHaveAttribute('data-title', '通知中心');
        expect(container.querySelector('main')).toHaveAttribute('data-eyebrow', '通知中心');
    });

    it('renders tender deadlines with the precise time and tender entry point', () => {
        render(
            <NotificationsIndex
                notifications={{ data: [], links: [] }}
                tenderNotifications={{
                    data: [{
                        id: 7,
                        status: 'active',
                        read_at: null,
                        type_label: '投标截止（今日）',
                        message: '招投标「厂房标的」即将到达投标截止时间。',
                        deadline_at: '2026-08-03T10:30:00+08:00',
                        tender: { code: 'ZB-001', name: '厂房标的' },
                        tender_url: '/objects/tender?record=tender-1&mode=detail',
                        read_url: '/tender-notifications/7/read',
                    }],
                    links: [],
                }}
                unreadCount={1}
            />,
        );

        expect(screen.getByText('投标截止（今日）')).toBeInTheDocument();
        expect(screen.getByText('ZB-001')).toBeInTheDocument();
        expect(screen.getByText('2026/08/03 10:30')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /查看招投标/ })).toHaveAttribute(
            'href',
            '/objects/tender?record=tender-1&mode=detail',
        );

        fireEvent.click(screen.getAllByRole('button', { name: /标为已读/ })[0]);

        expect(router.patch).toHaveBeenCalledWith(
            '/tender-notifications/7/read',
            {},
            {
                only: ['notifications', 'tenderNotifications', 'unreadCount', 'notificationUnreadCount'],
                preserveScroll: true,
                preserveState: true,
            },
        );
    });
});
