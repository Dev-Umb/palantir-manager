// @vitest-environment jsdom
import React from 'react';
import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import WechatScanLogin from './WechatScanLogin';
afterEach(() => { cleanup(); vi.restoreAllMocks(); });
const result = (data, status = 200) => ({ ok: status === 200, status, json: async () => data });
describe('WechatScanLogin', () => {
    it('only generates on request and displays the code without consuming pending authorization', async () => {
        const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(result({ id: 'abc', image: 'data:image/png;base64,cG5n' }));
        render(<WechatScanLogin />);
        expect(fetcher).not.toHaveBeenCalled();
        fireEvent.click(screen.getByText('微信扫码登录'));
        await screen.findByAltText('使用微信扫描此码登录');
        expect(fetcher).toHaveBeenCalledTimes(1);
        expect(fetcher.mock.calls[0][1].method).toBe('POST');
        expect(screen.getByRole('status').textContent).toContain('小程序内确认');
    });
    it('shows code generation errors and permits retry', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(result({ errors: { wechat: ['暂时无法生成登录码'] } }, 422));
        render(<WechatScanLogin />);
        fireEvent.click(screen.getByText('微信扫码登录'));
        await waitFor(() => expect(screen.getByRole('status').textContent).toBe('暂时无法生成登录码'));
        expect(screen.getByText('微信扫码登录').disabled).toBe(false);
        expect(screen.queryByRole('img')).toBeNull();
    });
});
