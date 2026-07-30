<?php

declare(strict_types=1);

it('creates an authenticated administrator browser session', function (): void {
    $page = visit(onlineBaseUrl().'/login')
        ->assertSee('登录')
        ->fill('input[type="email"]', onlineRoleEmail('admin'))
        ->fill('input[type="password"]', onlinePassword())
        ->press('登录')
        ->wait(2)
        ->assertPathIs('/')
        ->assertSee('系统管理员')
        ->assertNoJavaScriptErrors();

    expect($page->url())->toStartWith(onlineBaseUrl());
})->group('online', 'online-smoke')
    ->skip(fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1', 'Online regression is opt-in.');
