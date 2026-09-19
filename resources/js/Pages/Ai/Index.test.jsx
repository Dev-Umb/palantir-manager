// @vitest-environment jsdom

import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AiIndex from './Index';
import { HtmlArtifact, HtmlReportReader, htmlDocument } from './Artifacts';

const auth = vi.hoisted(() => ({ permissions: [] }));
beforeEach(() => { window.HTMLElement.prototype.scrollIntoView = vi.fn(); });
afterEach(() => { cleanup(); auth.permissions = []; vi.unstubAllGlobals(); });
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { auth } }),
}));

vi.mock('../../Components/Layout', () => ({
    default: ({ children, hideHeader = false, title }) => (
        <div data-testid="layout" data-hide-header={String(hideHeader)} data-title={title}>{children}</div>
    ),
}));

vi.mock('../../echo', () => ({
    getEcho: () => null,
}));

describe('AI assistant layout', () => {
    it('fills and focuses each timebook prompt without sending, and keeps it editable', () => {
        auth.permissions = ['timebook.view', 'timebook.ai.query', 'ai.harness.view'];
        vi.stubGlobal('fetch', vi.fn());
        render(<AiIndex conversations={[]} />);
        const composer = screen.getByRole('textbox');
        expect(screen.queryByRole('button', { name: '我的项目当前未回款合计多少' })).toBeNull();
        for (const prompt of ['XX累计工时及明细', '查询所有人本月累计工时', '查询所有人X月X日到X月X日累计工时']) {
            fireEvent.click(screen.getByRole('button', { name: prompt }));
            expect(composer.value).toBe(prompt);
            expect(document.activeElement).toBe(composer);
        }
        fireEvent.change(composer, { target: { value: '张三累计工时及明细' } });
        expect(composer.value).toBe('张三累计工时及明细');
        expect(fetch).not.toHaveBeenCalled();
    });

    it.each([{ permissions: [] }, { permissions: ['timebook.view'] }, { permissions: ['timebook.ai.query'] }])('preserves business prompts with incomplete timebook permissions: $permissions', ({ permissions }) => {
        auth.permissions = permissions;
        vi.stubGlobal('fetch', vi.fn());
        render(<AiIndex conversations={[]} />);
        expect(screen.queryByRole('button', { name: 'XX累计工时及明细' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: '我的项目当前未回款合计多少' }));
        expect(screen.getByRole('textbox').value).toBe('我的项目当前未回款合计多少');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('hides only the outer page title and keeps the conversation toolbar title', () => {
        window.HTMLElement.prototype.scrollIntoView = vi.fn();
        render(<AiIndex conversations={[]} />);

        expect(screen.getByTestId('layout').getAttribute('data-hide-header')).toBe('true');
        expect(screen.getByTestId('layout').getAttribute('data-title')).toBe('AI 数据助手');
        expect(screen.getByText('AI 数据助手')).not.toBeNull();
        expect(screen.getByRole('button', { name: /新对话/ })).not.toBeNull();
    });
});

describe('AI HTML artifact', () => {
    it('renders only inside a sandboxed iframe with a restrictive CSP', () => {
        const artifact = {
            id: 'html-1',
            type: 'html',
            title: '静态报告',
            data: { html: '<h2 onclick="alert(1)">报告</h2><script>alert(1)</script>' },
        };
        const onOpen = vi.fn();
        render(<HtmlArtifact artifact={artifact} onOpenReport={onOpen} />);
        fireEvent.click(screen.getByRole('button', { name: '打开报告：静态报告' }));
        expect(onOpen).toHaveBeenCalledWith(artifact, expect.anything());
        render(<HtmlReportReader artifact={artifact} onClose={vi.fn()} />);

        const frame = screen.getByTitle('静态报告');
        expect(frame.getAttribute('sandbox')).toBe('');
        expect(frame.getAttribute('referrerpolicy')).toBe('no-referrer');
        expect(frame.getAttribute('srcdoc')).toContain("default-src 'none'");
        expect(frame.getAttribute('srcdoc')).not.toContain('<script');
        expect(frame.getAttribute('srcdoc')).not.toContain('onclick');
    });

    it('removes remote executable markup before building the iframe document', () => {
        const document = htmlDocument('<img src="https://evil.example/x"><iframe src="https://evil.example"></iframe>');

        expect(document).not.toContain('<iframe');
        expect(document).toContain("img-src data:");
    });
});
