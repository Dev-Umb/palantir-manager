// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import QuotationIndex, { QuotePaper, emptyParameters } from './Index';

vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({href,children,...rest}) => <a href={href} {...rest}>{children}</a>, router: {post: vi.fn()}, usePage: () => ({ url: '/quotations', props: { auth: {user: {name: '业务员甲'}, roles: []}, nav: [{ key: 'ai', label: 'AI 数据助手', href: '/ai', visible: true }, { key: 'quotations', label: '参考报价', href: '/quotations', visible: true }] } }) }));
const props = { owner: { id: 1, name: '业务员甲' }, today: '2026-10-05', projects: [], archives: { data: [], total: 0, current_page: 1, last_page: 1 }, urls: Object.fromEntries(['chat', 'market', 'history', 'price', 'calculate', 'adopt', 'archives', 'export'].map((key) => [key, '/quotations/' + key])) };
const complete = { project_name: '测试项目', customer: '客户', product: '梁模板', spec: '板厚6mm', quantity: '74', tax_rate: '13', tax_basis: '含税', shipping: '含运费', destination: '太原现场', market: '太原', material: 'Q235B', steel_spec: '6mm' };
const labels = { project_name: '项目名称', customer: '客户名称', product: '产品名称', spec: '规格 / 构造', quantity: '数量', tax_rate: '税率', tax_basis: '基价税口径', shipping: '运费口径', destination: '交付地点', market: '市场', material: '材质', steel_spec: '钢材规格' };
const snapshot = { params: { ...emptyParameters(props.today), ...complete }, owner: props.owner, generated_at: '2026-10-05T10:00:00+08:00', fee: { amount: '2316', source_name: '用户指定', date: props.today, confirmed_at: '2026-10-05T10:00:00+08:00' }, steel: { amount: '3485', source_name: '供应商报价', date: props.today, confirmed_at: '2026-10-05T10:00:00+08:00' }, calculation: { quantity_millis: 74000, unit_price_cents: 580100, total_cents: 42927400, net_cents: 37988850, tax_cents: 4938550, formula: '基价×数量' } };
const ok = (body) => Promise.resolve({ ok: true, json: async () => body });
beforeEach(() => {
    HTMLDialogElement.prototype.showModal = function () { this.open = true; };
    HTMLDialogElement.prototype.close = function () { this.open = false; };
    vi.stubGlobal('fetch', vi.fn((url, options) => {
        if (url.endsWith('/price')) return ok({ token: JSON.parse(options.body).kind + '-token', price: { amount: '1', source_name: '用户指定', date: props.today } });
        if (url.endsWith('/calculate')) return ok({ snapshot, preview_token: 'signed-preview' });
        if (url.includes('/archives')) return ok({ archives: props.archives });
        return ok({});
    }));
});
afterEach(() => { cleanup(); vi.unstubAllGlobals(); });
function fill() { Object.entries(complete).forEach(([key, value]) => fireEvent.change(screen.getByLabelText(labels[key]), { target: { value } })); }
async function confirmPrices() {
    fireEvent.change(screen.getByLabelText('我提供的加工费'), { target: { value: '2316' } });
    fireEvent.change(screen.getByLabelText('我提供的材料基价'), { target: { value: '3485' } });
    screen.getAllByLabelText('价格来源说明').forEach((input) => fireEvent.change(input, { target: { value: '用户指定' } }));
    fireEvent.click(screen.getAllByText('确认使用我的价格')[0]);
    await waitFor(() => expect(screen.getAllByText('已确认')).toHaveLength(1));
    fireEvent.click(screen.getAllByText('确认使用我的价格')[1]);
    await waitFor(() => expect(screen.getAllByText('已确认')).toHaveLength(2));
}
describe('independent reference quotations', () => {
    it('retains main AI entry and enforces independent confirmation and context invalidation', async () => {
        render(<QuotationIndex {...props} />); fill();
        expect(screen.getAllByRole('link', { name: 'AI 数据助手' })[0]).toHaveAttribute('href', '/ai');
        expect(screen.getByText('确认以上参数并计算')).toBeDisabled();
        await confirmPrices(); expect(screen.getByText('确认以上参数并计算')).toBeEnabled();
        fireEvent.change(screen.getByLabelText('数量'), { target: { value: '80' } });
        expect(screen.getAllByText('已确认')).toHaveLength(2);
        fireEvent.change(screen.getByLabelText('规格 / 构造'), { target: { value: '板厚8mm' } });
        expect(screen.getAllByText('待确认')).toHaveLength(2);
        expect(screen.getByText('确认以上参数并计算')).toBeDisabled();
    });
    it('AI candidates wait for user action and never silently replace parameters', async () => {
        fetch.mockImplementation(() => ok({ answer: '请核对重量', questions: ['请确认税率'], proposals: [{ field: 'quantity', value: '74', confidence: 'low', evidence: '图纸74吨' }] }));
        render(<QuotationIndex {...props} />);
        fireEvent.change(screen.getByLabelText('补充文本'), { target: { value: '请识别图纸' } });
        fireEvent.click(screen.getByText('发送给报价助手'));
        await screen.findByText('数量：74');
        expect(screen.getByLabelText('数量')).toHaveValue(null);
        fireEvent.click(screen.getByText('确认填入'));
        expect(screen.getByLabelText('数量')).toHaveValue(74);
        expect(screen.getAllByText('待确认')).toHaveLength(2);
    });
    it('keeps a failed adoption reviewable and retries with the same idempotency key', async () => {
        let saves = 0;
        const keys = [];
        const original = fetch.getMockImplementation();
        fetch.mockImplementation((url, options) => {
            if (url.endsWith('/adopt')) {
                keys.push(JSON.parse(options.body).adoption_key);
                return ++saves === 1 ? Promise.resolve({ ok: false, status: 500, json: async () => ({ message: '保存失败，请重试' }) }) : ok({ archive: { id: 'q1' } });
            }
            return original(url, options);
        });
        render(<QuotationIndex {...props} />); fill(); await confirmPrices();
        fireEvent.click(screen.getByText('确认以上参数并计算')); await screen.findByText('¥ 429,274.00', { selector: '.q-total' });
        fireEvent.click(screen.getByText('采纳并独立留档'));
        fireEvent.click(screen.getByText('确认采纳并永久留档'));
        await screen.findAllByText('保存失败，请重试');
        fireEvent.click(screen.getByText('确认采纳并永久留档'));
        await screen.findByText('已保存到我的档案');
        expect(keys).toHaveLength(2); expect(keys[0]).toBe(keys[1]);
    });
    it('shows inline confirmation cards and explains missing inputs before confirming prices', () => {
        render(<QuotationIndex {...props} />);
        expect(document.querySelector('.q-columns')).toBeNull();
        expect(document.querySelector('.app-shell .desktop-rail')).not.toBeNull();
        expect(document.querySelector('.workspace .q-shell')).not.toBeNull();
        expect(screen.getByText('报价助手 · 参数确认卡')).toBeInTheDocument();
        expect(screen.getByText('当天材料市场与交付要求')).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('我提供的加工费'), { target: { value: '2316' } });
        fireEvent.change(screen.getAllByLabelText('价格来源说明')[0], { target: { value: '用户指定' } });
        expect(screen.getAllByText('确认使用我的价格')[0]).toBeDisabled();
        expect(screen.getByText(/请先补充：/)).toHaveTextContent('产品名称');
    });
    it('keeps quote archives in a collapsible sidebar and removes the unrelated page header', () => {
        render(<QuotationIndex {...props} />);
        expect(screen.queryByRole('heading', { name: 'AI 参考报价' })).toBeNull();
        expect(screen.getByRole('complementary', { name: '报价菜单' })).toContainElement(screen.getByText('我的报价档案（0）'));
        fireEvent.click(screen.getByRole('button', { name: '收起报价菜单' }));
        expect(screen.getByRole('button', { name: '展开报价菜单' })).toHaveAttribute('aria-expanded', 'false');
        fireEvent.click(screen.getByRole('button', { name: '展开报价菜单' }));
        expect(screen.getByRole('button', { name: '收起报价菜单' })).toHaveAttribute('aria-expanded', 'true');
    });
    it('opens the archived snapshot from the left menu and reuses parameters without old prices', async () => {
        const row = { id: 'q1', title: '测试报价留档', total_cents: 42927400, created_at: snapshot.generated_at, show_url: '/archive/q1', download_url: '/archive/q1/download' };
        fetch.mockImplementation(() => ok({ archive: row, snapshot }));
        render(<QuotationIndex {...props} archives={{ ...props.archives, data: [row], total: 1 }} />);
        const menu = screen.getByRole('complementary', { name: '报价菜单' });
        expect(menu).toContainElement(screen.getByText('测试报价留档'));
        fireEvent.click(screen.getByRole('button', { name: /测试报价留档/ }));
        await screen.findByRole('button', { name: '以此重新报价' });
        expect(screen.getByRole('link', { name: '下载 Excel 兼容 CSV' })).toHaveAttribute('href', row.download_url);
        fireEvent.click(screen.getByRole('button', { name: '以此重新报价' }));
        expect(screen.getByLabelText('数量')).toHaveValue(74);
        expect(screen.getByLabelText('价格日期')).toHaveValue(props.today);
        expect(screen.getAllByText('待确认')).toHaveLength(2);
    });
    it('downloads CSV without navigating away or losing the calculated preview', async () => {
        const original = fetch.getMockImplementation();
        fetch.mockImplementation((url, options) => url.endsWith('/export') ? Promise.resolve({ ok: true, blob: async () => new Blob(['验收报价'], { type: 'text/csv' }) }) : original(url, options));
        const createUrl = vi.fn(() => 'blob:quotation-export');
        vi.stubGlobal('URL', { createObjectURL: createUrl, revokeObjectURL: vi.fn() });
        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () { expect(this.download).toBe('reference-quotation.csv'); });
        render(<QuotationIndex {...props} />); fill(); await confirmPrices();
        fireEvent.click(screen.getByText('确认以上参数并计算')); await screen.findByText('¥ 429,274.00', { selector: '.q-total' });
        fireEvent.click(screen.getByText('下载 Excel 兼容 CSV'));
        await waitFor(() => expect(click).toHaveBeenCalledTimes(1));
        expect(screen.getByText('¥ 429,274.00', { selector: '.q-total' })).toBeInTheDocument();
        expect(document.querySelector('form')).toBeNull();
        click.mockRestore();
    });
    it('leaves text and file inputs available for retry when AI fails', async () => {
        fetch.mockResolvedValue({ ok: false, status: 502, json: async () => ({ message: 'AI 暂不可用' }) });
        render(<QuotationIndex {...props} />);
        fireEvent.change(screen.getByLabelText('补充文本'), { target: { value: '预计80吨' } });
        fireEvent.click(screen.getByText('发送给报价助手'));
        await screen.findByRole('alert');
        expect(screen.getByLabelText('补充文本')).toHaveValue('预计80吨');
        expect(screen.getByText('发送给报价助手')).toBeEnabled();
    });
    it('distinguishes unknown totals from confirmed zero and preserves provenance', () => {
        render(<QuotePaper snapshot={{ ...snapshot, calculation: { ...snapshot.calculation, quantity_millis: null, total_cents: null, net_cents: null, tax_cents: null, unit_price_cents: 0 } }} />);
        expect(screen.getByText('未知')).toBeInTheDocument();
        expect(screen.getByText('仅出单价')).toBeInTheDocument();
        expect(screen.getByText('供应商报价', { exact: false })).toBeInTheDocument();
        expect(screen.getByText('基价×数量')).toBeInTheDocument();
    });
});
