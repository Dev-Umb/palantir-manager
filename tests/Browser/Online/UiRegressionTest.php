<?php

declare(strict_types=1);

$xycConfig = require dirname(__DIR__, 3).'/config/xyc.php';

$objectFields = collect($xycConfig['objects'])
    ->reject(fn (array $object): bool => (bool) ($object['archived'] ?? false))
    ->mapWithKeys(fn (array $object): array => [
        $object['key'] => collect($object['fields'] ?? [])->pluck('label')->all(),
    ])
    ->all();

dataset('active roles', [
    ['admin'],
    ['business'],
    ['engineering'],
    ['procurement'],
    ['production_manager'],
    ['production'],
    ['finance'],
]);

it('opens every active object form and exposes every configured field', function () use ($objectFields): void {
    $page = visitOnlineAs('admin');

    foreach (collect($objectFields)->except('customer_contact') as $key => $labels) {
        $page->navigate(onlineBaseUrl()."/objects/{$key}?mode=create")
            ->wait(0.5)
            ->assertSee('新建')
            ->assertNoJavaScriptErrors();

        foreach ($labels as $label) {
            expect($page->content(), "Missing field {$label} in {$key}")->toContain($label);
        }

        $geometry = $page->script(<<<'JS'
        () => {
            const modal = document.querySelector('.modal-panel');
            const controls = [...document.querySelectorAll('.modal-panel input, .modal-panel select, .modal-panel textarea, .modal-panel button')];
            return {
                hasModal: Boolean(modal),
                modalOverflowsViewport: modal
                    ? modal.getBoundingClientRect().right > innerWidth || modal.getBoundingClientRect().bottom > innerHeight
                    : true,
                invisibleControls: controls.filter((element) => {
                    const rect = element.getBoundingClientRect();
                    return rect.width < 1 || rect.height < 1;
                }).length,
                unnamedControls: controls.filter((element) => {
                    if (element.tagName === 'BUTTON') return !(element.innerText || element.getAttribute('aria-label'));
                    return !element.closest('label') && !element.getAttribute('aria-label') && !element.id;
                }).length,
            };
        }
    JS);

        expect($geometry, "Invalid form geometry for {$key}")->toMatchArray([
            'hasModal' => true,
            'modalOverflowsViewport' => false,
            'invisibleControls' => 0,
            'unnamedControls' => 0,
        ]);
    }
})->group('online', 'online-ui', 'online-fields')
    ->skip(fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1', 'Online regression is opt-in.');

it('exercises every active object toolbar and modal close control', function () use ($objectFields): void {
    $page = visitOnlineAs('admin');

    foreach (collect($objectFields)->except('customer_contact')->keys() as $key) {
        $page->navigate(onlineBaseUrl()."/objects/{$key}")
            ->waitForText('应用')
            ->fill('input[aria-label="搜索记录"]', '不存在的边界值')
            ->select('select[aria-label="每页条数"]', '25')
            ->select('select[aria-label="排序方向"]', 'desc')
            ->click('.filter-button')
            ->wait(0.3)
            ->assertQueryStringHas('q', '不存在的边界值')
            ->assertQueryStringHas('per_page', '25')
            ->assertQueryStringHas('direction', 'desc')
            ->navigate(onlineBaseUrl()."/objects/{$key}?mode=create")
            ->waitForText('新建')
            ->click('.modal-head .icon-link')
            ->wait(0.2)
            ->assertPathIs("/objects/{$key}");
    }
})->group('online', 'online-ui', 'online-toolbar')
    ->skip(fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1', 'Online regression is opt-in.');

it('keeps core pages inside each responsive viewport', function (): void {
    $page = visitOnlineAs('admin', '/objects/customer');
    $viewports = [
        ['desktop', 1440, 960],
        ['compact desktop', 1024, 768],
        ['tablet', 768, 1024],
        ['mobile', 390, 844],
    ];

    foreach ($viewports as [$name, $width, $height]) {
        $page->resize($width, $height)
            ->wait(0.5)
            ->assertNoJavaScriptErrors();

        $layout = $page->script(<<<'JS'
        () => ({
            viewport: innerWidth,
            bodyOverflow: document.documentElement.scrollWidth > innerWidth + 1,
            offscreenInteractive: [...document.querySelectorAll('button, a, input, select, textarea')]
                .filter((element) => {
                    const style = getComputedStyle(element);
                    const rect = element.getBoundingClientRect();
                    return style.display !== 'none'
                        && style.visibility !== 'hidden'
                        && rect.width > 1
                        && !element.closest('.ag-root')
                        && (rect.right > innerWidth + 1 || rect.left < -1);
                })
                .map((element) => element.getAttribute('aria-label') || element.innerText || element.tagName)
                .slice(0, 10),
        })
    JS);

        expect($layout['bodyOverflow'], $name.' has document-level horizontal overflow')->toBeFalse();
        if ($layout['offscreenInteractive'] !== []) {
            throw new RuntimeException($name.' has unreachable controls: '.json_encode($layout['offscreenInteractive'], JSON_UNESCAPED_UNICODE));
        }
    }
})->group('online', 'online-ui', 'online-responsive')
    ->skip(fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1', 'Online regression is opt-in.');

it('does not clip the row overflow menu inside the grid', function (): void {
    $page = visitOnlineAs('admin', '/objects/customer')
        ->waitForText('查看');

    $menu = $page->script(<<<'JS'
        async () => {
            const triggers = [...document.querySelectorAll('.row-actions-menu-trigger')];
            const trigger = triggers[Math.min(2, triggers.length - 1)];
            trigger?.click();
            await new Promise((resolve) => setTimeout(resolve, 100));
            const menu = document.querySelector('.row-actions-menu-popup');
            if (!trigger || !menu) return { exists: false };
            const rect = menu.getBoundingClientRect();
            const points = [
                [rect.left + 10, rect.top + 10],
                [rect.right - 10, rect.top + 10],
                [rect.left + 10, rect.bottom - 10],
                [rect.right - 10, rect.bottom - 10],
            ];
            return {
                exists: true,
                insideViewport: rect.left >= 0 && rect.top >= 0 && rect.right <= innerWidth && rect.bottom <= innerHeight,
                visibleCorners: points.filter(([x, y]) => {
                    const hit = document.elementFromPoint(x, y);
                    return hit && (hit === menu || menu.contains(hit));
                }).length,
                height: rect.height,
            };
        }
    JS);

    expect($menu)->toMatchArray([
        'exists' => true,
        'insideViewport' => true,
        'visibleCorners' => 4,
    ])->and($menu['height'])->toBeGreaterThan(30);
})->group('online', 'online-ui', 'online-actions')
    ->skip(fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1', 'Online regression is opt-in.');

it('keeps every mobile route reachable inside the More panel', function (): void {
    $page = visitOnlineAs('admin', '/objects/customer')
        ->resize(390, 844)
        ->click('.mobile-nav button[aria-label="更多业务入口"]')
        ->waitForText('更多业务入口')
        ->assertNoJavaScriptErrors();

    $panel = $page->script(<<<'JS'
        () => {
            const dialog = document.querySelector('.mobile-more-panel');
            const rect = dialog?.getBoundingClientRect();
            const controls = [...(dialog?.querySelectorAll('a, button') || [])];

            return {
                activeLabel: document.activeElement?.getAttribute('aria-label'),
                insideViewport: Boolean(rect && rect.left >= 0 && rect.top >= 0 && rect.right <= innerWidth && rect.bottom <= innerHeight),
                offscreenControls: controls.filter((element) => {
                    const controlRect = element.getBoundingClientRect();
                    return controlRect.left < 0 || controlRect.right > innerWidth || controlRect.top < 0 || controlRect.bottom > innerHeight;
                }).length,
                labels: controls.map((element) => element.getAttribute('aria-label') || element.innerText.trim()),
            };
        }
    JS);

    expect($panel)->toMatchArray([
        'activeLabel' => '关闭更多业务入口',
        'insideViewport' => true,
        'offscreenControls' => 0,
    ]);
    expect($panel['labels'])->toContain('提交采购申请', '采购OA审批', '现场报工', '用户与权限', 'AI 数据助手', '退出');

    $closed = $page->script(<<<'JS'
        async () => {
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
            await new Promise((resolve) => setTimeout(resolve, 50));
            return !document.querySelector('.mobile-more-panel');
        }
    JS);
    expect($closed)->toBeTrue();
})->group('online', 'online-ui', 'online-mobile-navigation')
    ->skip(fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1', 'Online regression is opt-in.');

it('keeps list summaries compact and opens contact list detail and edit states', function (): void {
    $page = visitOnlineAs('admin', '/objects/customer')
        ->waitForText('查看');

    $summary = $page->script(<<<'JS'
        () => {
            const rows = [...document.querySelectorAll('.ag-center-cols-container .ag-row')];
            const button = document.querySelector('.list-summary-trigger');
            const headers = [...document.querySelectorAll('.grid-header-label')];
            button?.click();

            return {
                opened: Boolean(button),
                rowHeights: rows.map((row) => Math.round(row.getBoundingClientRect().height)),
                inlineContactControls: document.querySelectorAll('.customer-contact-detail, .customer-contact-create').length,
                headersHaveTitles: headers.length > 0 && headers.every((header) => header.title === header.textContent.trim()),
            };
        }
    JS);
    expect($summary)->toMatchArray([
        'opened' => true,
        'inlineContactControls' => 0,
        'headersHaveTitles' => true,
    ]);
    expect($summary['rowHeights'])->not->toBeEmpty();
    expect(array_unique($summary['rowHeights']))->toBe([44]);

    $page->waitForText('客户联系人')
        ->assertPresent('.contact-modal-list')
        ->assertPresent('.contact-modal-list-item')
        ->assertNoJavaScriptErrors();

    expect($page->script('() => document.activeElement?.getAttribute("aria-label")'))->toBe('关闭联系人弹窗');

    $page->click('.contact-modal-list-item')
        ->waitForText('联系人详情')
        ->assertSee('关联项目')
        ->assertNoJavaScriptErrors();

    $page->click('.contact-modal-footer .action-button')
        ->waitForText('编辑联系人')
        ->assertPresent('.contact-modal-panel form')
        ->assertNoJavaScriptErrors();

    expect($page->script('() => document.activeElement?.getAttribute("aria-label") || document.activeElement?.closest("label")?.innerText'))->toContain('联系人姓名');

    $geometry = $page->script(<<<'JS'
        () => {
            const modal = document.querySelector('.contact-modal-panel');
            const rect = modal?.getBoundingClientRect();
            return {
                visible: Boolean(rect && rect.width > 0 && rect.height > 0),
                insideViewport: Boolean(rect && rect.left >= 0 && rect.top >= 0 && rect.right <= innerWidth && rect.bottom <= innerHeight),
                hasHorizontalOverflow: Boolean(modal && modal.scrollWidth > modal.clientWidth + 1),
            };
        }
    JS);
    expect($geometry)->toMatchArray([
        'visible' => true,
        'insideViewport' => true,
        'hasHorizontalOverflow' => false,
    ]);
})->group('online', 'online-ui', 'online-contact')
    ->skip(fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1', 'Online regression is opt-in.');

it('logs in every active role and renders its permitted navigation', function (string $role): void {
    visitOnlineAs($role)
        ->assertNoJavaScriptErrors()
        ->assertSee('退出');
})->with('active roles')
    ->group('online', 'online-role')
    ->skip(fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1', 'Online regression is opt-in.');
