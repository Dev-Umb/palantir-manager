<?php

declare(strict_types=1);

it('registers deletes and rejects login for a run scoped user', function (): void {
    $runId = (string) getenv('ONLINE_REGRESSION_RUN_ID');
    $name = "{$runId} 待删除用户";
    $email = strtolower($runId).'@example.test';
    $password = "Delete-{$runId}!";

    visit(onlineBaseUrl().'/register')
        ->fill('.auth-form label:nth-of-type(1) input', $name)
        ->fill('.auth-form label:nth-of-type(2) input', $email)
        ->fill('.auth-form label:nth-of-type(3) input', $password)
        ->fill('.auth-form label:nth-of-type(4) input', $password)
        ->press('注册并进入')
        ->wait(1)
        ->assertPathIs('/')
        ->assertSee($name)
        ->assertNoJavaScriptErrors();

    $administrator = visitOnlineAs('admin', '/admin/rbac')
        ->waitForText($email)
        ->assertSee($email);

    $availability = $administrator->script(<<<JS
        () => {
            const rows = [...document.querySelectorAll('.user-row')];
            const target = rows.find((row) => row.innerText.includes('{$email}'));

            return {
                targetCanDelete: Boolean(target?.querySelector('.icon-danger:not(:disabled)')),
                selfDisabled: Boolean(document.querySelector('.icon-danger:disabled')),
                selfReason: document.body.innerText.includes('不能删除当前登录账号'),
            };
        }
    JS);

    expect($availability)->toMatchArray([
        'targetCanDelete' => true,
        'selfDisabled' => true,
        'selfReason' => true,
    ]);

    $deleted = $administrator->script(<<<JS
        async () => {
            window.confirm = () => true;
            const row = [...document.querySelectorAll('.user-row')]
                .find((candidate) => candidate.innerText.includes('{$email}'));
            const button = row?.querySelector('.icon-danger:not(:disabled)');
            if (!button) return false;
            button.click();
            await new Promise((resolve) => setTimeout(resolve, 1200));
            return true;
        }
    JS);

    expect($deleted)->toBeTrue();

    $administrator->refresh()
        ->wait(1)
        ->assertDontSee($email)
        ->assertSee('用户与权限')
        ->assertNoJavaScriptErrors();

    visit(onlineBaseUrl().'/login')
        ->fill('input[type="email"]', $email)
        ->fill('input[type="password"]', $password)
        ->press('登录')
        ->wait(1)
        ->assertPathIs('/login')
        ->assertSee('账号或密码不正确。');
})->group('online', 'online-write', 'online-rbac')
    ->skip(
        fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1'
            || getenv('ONLINE_REGRESSION_ALLOW_MUTATIONS') !== '1'
            || getenv('ONLINE_REGRESSION_RUN_ID') === false,
        'Online mutations require explicit opt-in and a run id.',
    );
