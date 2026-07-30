<?php

declare(strict_types=1);
use Tests\TestCase;

uses(TestCase::class)->in('Browser');

pest()->browser()
    ->inChrome()
    ->inLightMode()
    ->timeout(20_000);

function onlineBaseUrl(): string
{
    return rtrim((string) getenv('ONLINE_BASE_URL'), '/');
}

function onlinePassword(): string
{
    return (string) getenv('ONLINE_ADMIN_PASSWORD');
}

function onlineRoleEmail(string $role): string
{
    return $role === 'admin'
        ? (string) getenv('ONLINE_ADMIN_EMAIL')
        : "{$role}@xyc.test";
}

function visitOnlineAs(string $role, string $path = '/')
{
    $page = visit(onlineBaseUrl().'/login')
        ->fill('input[type="email"]', onlineRoleEmail($role))
        ->fill('input[type="password"]', onlinePassword());

    if ($role === 'admin') {
        $page->wait(13);
    }

    return $page
        ->press('登录')
        ->wait(1)
        ->assertPathIs('/')
        ->assertSee($role === 'admin' ? '系统管理员' : onlineRoleLabel($role))
        ->navigate(onlineBaseUrl().$path)
        ->wait(1);
}

function onlineRoleLabel(string $role): string
{
    return match ($role) {
        'business' => '业务员',
        'engineering' => '技术员',
        'procurement' => '采购员',
        'production_manager' => '生产负责人',
        'production' => '生产员',
        'finance' => '财务员',
        default => $role,
    };
}
