<?php

declare(strict_types=1);

it('creates edits views searches and deletes a run-scoped customer through the browser', function (): void {
    $runId = (string) getenv('ONLINE_REGRESSION_RUN_ID');
    $name = "{$runId} 客户边界";
    $updatedName = "{$runId} 客户已编辑";

    $page = visitOnlineAs('admin', '/objects/customer?mode=create')
        ->fill('input[name="name"]', $name)
        ->fill('input[name="address"]', '边界地址 <script>alert(1)</script>');

    expect($page->script('() => Boolean(document.querySelector(\'input[name="cooperation_history"]\'))'))
        ->toBeFalse();

    $page->assertSee('合作历史')
        ->fill('input[name="remark"]', $runId)
        ->click('.modal-panel button[type="submit"]')
        ->waitForText($name)
        ->assertNoJavaScriptErrors();

    $record = $page->script(<<<JS
        () => {
            const row = [...document.querySelectorAll('.ag-row')].find((candidate) => candidate.innerText.includes('{$runId}'));
            const href = row?.querySelector('a[href*="record="]')?.href;
            return href ? { href, id: new URL(href).searchParams.get('record') } : null;
        }
    JS);

    expect($record)->not->toBeNull()
        ->and($record['id'])->not->toBe('');

    $page->navigate($record['href'])
        ->waitForText('详情')
        ->assertSee($name)
        ->assertSee('边界地址 <script>alert(1)</script>')
        ->assertNoJavaScriptErrors()
        ->navigate(onlineBaseUrl()."/objects/customer?record={$record['id']}&mode=edit")
        ->waitForText('编辑')
        ->fill('input[name="name"]', $updatedName)
        ->click('.modal-panel button[type="submit"]')
        ->waitForText($updatedName);

    $page->fill('input[aria-label="搜索记录"]', $runId)
        ->press('应用')
        ->waitForText($updatedName)
        ->assertSee($updatedName);

    $deleted = $page->script(<<<JS
        async () => {
            window.confirm = () => true;
            const row = [...document.querySelectorAll('.ag-row')].find((candidate) => candidate.innerText.includes('{$runId}'));
            const trigger = row?.querySelector('.row-actions-menu-trigger');
            if (!trigger) return false;
            trigger.click();
            await new Promise((resolve) => setTimeout(resolve, 100));
            document.querySelector('.row-actions-menu-popup button.danger')?.click();
            await new Promise((resolve) => setTimeout(resolve, 1200));
            return true;
        }
    JS);

    expect($deleted)->toBeTrue();
    $page->refresh()
        ->wait(1)
        ->assertDontSee($updatedName);
})->group('online', 'online-write', 'online-crud')
    ->skip(
        fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1'
            || getenv('ONLINE_REGRESSION_ALLOW_MUTATIONS') !== '1'
            || getenv('ONLINE_REGRESSION_RUN_ID') === false,
        'Online mutations require explicit opt-in and a run id.',
    );
