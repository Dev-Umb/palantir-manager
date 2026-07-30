<?php

declare(strict_types=1);

it('smoke tests every non-object page and its primary controls', function (): void {
    $page = visitOnlineAs('admin');
    $pages = [
        ['/', '经营大盘'],
        ['/notifications', '通知中心'],
        ['/requests/create', '采购申请'],
        ['/procurement/approvals', '采购申请'],
        ['/team-log', '现场报工'],
        ['/admin/rbac', '用户与权限'],
        ['/ai', 'AI 数据助手'],
    ];

    foreach ($pages as [$path, $expectedText]) {
        $page->navigate(onlineBaseUrl().$path)
            ->waitForText($expectedText)
            ->assertSee($expectedText)
            ->assertNoJavaScriptErrors();

        $unnamedButtons = $page->script(<<<'JS'
            () => [...document.querySelectorAll('button')]
                .filter((button) => {
                    const rect = button.getBoundingClientRect();
                    return rect.width > 1
                        && rect.height > 1
                        && !(button.textContent.trim() || button.getAttribute('aria-label') || button.title);
                })
                .map((button) => button.outerHTML.slice(0, 220))
        JS);
        if ($unnamedButtons !== []) {
            throw new RuntimeException("{$path} has visible unnamed buttons: ".json_encode($unnamedButtons, JSON_UNESCAPED_UNICODE));
        }
    }
})->group('online', 'online-ui', 'online-pages')
    ->skip(fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1', 'Online regression is opt-in.');

it('keeps public authentication and submission pages reachable', function (): void {
    $pages = [
        ['/login', '登录'],
        ['/register', '注册'],
        ['/purchase-request', '采购申请'],
    ];

    foreach ($pages as [$path, $expectedText]) {
        visit(onlineBaseUrl().$path)
            ->assertSee($expectedText)
            ->assertNoJavaScriptErrors();
    }
})->group('online', 'online-ui', 'online-public')
    ->skip(fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1', 'Online regression is opt-in.');
