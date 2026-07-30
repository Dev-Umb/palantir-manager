import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire('/Users/umb/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/');
const { chromium } = require('playwright');

const baseUrl = process.env.BASE_URL ?? 'https://palantir.umb.ink';
const outDir = process.env.UI_AUDIT_DIR ?? 'docs/ui-audit-20260707';
fs.mkdirSync(outDir, { recursive: true });

const accounts = {
    admin: 'admin@xyc.test',
    business: 'business@xyc.test',
    engineering: 'engineering@xyc.test',
    production: 'production@xyc.test',
    procurement: 'procurement@xyc.test',
    warehouse: 'warehouse@xyc.test',
    finance: 'finance@xyc.test',
};

const pages = [
    ['login', null, '/login'],
    ['public-purchase', null, '/purchase-request'],
    ['team-log-production', 'production', '/team-log'],
    ['dashboard-admin', 'admin', '/'],
    ['objects-project-admin', 'admin', '/objects/project'],
    ['project-create-modal', 'admin', '/objects/project?mode=create'],
    ['objects-purchase-procurement', 'procurement', '/objects/purchase'],
    ['objects-material-warehouse', 'warehouse', '/objects/material'],
    ['procurement-approvals', 'procurement', '/procurement/approvals'],
    ['rbac-admin', 'admin', '/admin/rbac'],
];

async function login(page, role) {
    await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[type=email]', accounts[role]);
    await page.fill('input[type=password]', process.env.TEST_PASSWORD ?? 'password123');
    await Promise.all([
        page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 }).catch(() => {}),
        page.getByRole('button', { name: '登录' }).click(),
    ]);
}

async function inspect(page) {
    return page.evaluate(() => {
        const vw = document.documentElement.clientWidth;
        const bodyText = document.body.innerText.trim();
        const nodes = [...document.querySelectorAll('body *')];
        const visible = (el) => {
            const r = el.getBoundingClientRect();
            const s = getComputedStyle(el);
            return r.width > 1 && r.height > 1 && s.visibility !== 'hidden' && s.display !== 'none';
        };
        const brief = (el) => {
            const r = el.getBoundingClientRect();
            return {
                tag: el.tagName.toLowerCase(),
                cls: String(el.className || '').slice(0, 70),
                text: (el.innerText || el.title || el.ariaLabel || '').trim().replace(/\s+/g, ' ').slice(0, 90),
                rect: { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), width: Math.round(r.width), height: Math.round(r.height) },
            };
        };

        return {
            url: location.href,
            title: document.title,
            blank: bodyText.length < 20,
            pageOverflow: document.documentElement.scrollWidth > vw + 1,
            overflow: nodes.filter((el) => visible(el) && (el.getBoundingClientRect().right > vw + 1 || el.getBoundingClientRect().left < -1)).slice(0, 10).map(brief),
            clippedText: nodes.filter((el) => {
                const s = getComputedStyle(el);
                const text = (el.innerText || '').trim();
                return visible(el) && text.length > 4 && (s.overflow === 'hidden' || s.overflowX === 'hidden') && el.scrollWidth > el.clientWidth + 2;
            }).slice(0, 10).map((el) => ({ ...brief(el), scrollWidth: el.scrollWidth, clientWidth: el.clientWidth })),
            smallTargets: [...document.querySelectorAll('button,a')].filter((el) => {
                const r = el.getBoundingClientRect();
                return visible(el) && (r.width < 30 || r.height < 30);
            }).slice(0, 10).map(brief),
            modals: [...document.querySelectorAll('.modal-panel')].map((el) => {
                const r = el.getBoundingClientRect();
                return { width: Math.round(r.width), height: Math.round(r.height), scrollHeight: el.scrollHeight, clientHeight: el.clientHeight };
            }),
        };
    });
}

const browser = await chromium.launch({ headless: true });
const findings = [];

for (const viewport of [{ name: 'desktop', width: 1440, height: 960 }, { name: 'mobile', width: 390, height: 844 }]) {
    const contexts = new Map();
    for (const [name, role, url] of pages) {
        const key = `${viewport.name}-${role || 'public'}`;
        let context = contexts.get(key);
        if (!context) {
            context = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height }, ignoreHTTPSErrors: true });
            contexts.set(key, context);
            if (role) {
                const loginPage = await context.newPage();
                await login(loginPage, role);
                await loginPage.close();
            }
        }

        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('console', (message) => {
            if (message.type() === 'error') errors.push(message.text());
        });
        await page.goto(`${baseUrl}${url}`, { waitUntil: 'networkidle', timeout: 30000 });
        await page.screenshot({ path: path.join(outDir, `${viewport.name}-${name}.png`), fullPage: true });
        findings.push({ page: name, role: role || 'public', viewport: viewport.name, errors, ...(await inspect(page)) });
        await page.close();
    }
    for (const context of contexts.values()) await context.close();
}

await browser.close();

fs.writeFileSync(path.join(outDir, 'findings.json'), JSON.stringify(findings, null, 2));
console.log(JSON.stringify({
    outDir,
    checked: findings.length,
    candidates: findings
        .filter((item) => item.blank || item.errors.length || item.pageOverflow || item.overflow.length || item.clippedText.length || item.smallTargets.length)
        .map((item) => ({
            page: item.page,
            viewport: item.viewport,
            blank: item.blank,
            errors: item.errors.length,
            pageOverflow: item.pageOverflow,
            overflow: item.overflow.length,
            clippedText: item.clippedText.length,
            smallTargets: item.smallTargets.length,
        })),
}, null, 2));
