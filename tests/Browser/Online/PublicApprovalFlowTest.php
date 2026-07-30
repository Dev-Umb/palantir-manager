<?php

declare(strict_types=1);

it('validates submits rejects and cleans a public purchase request', function (): void {
    $runId = (string) getenv('ONLINE_REGRESSION_RUN_ID');
    $reason = "{$runId} 公开采购边界";

    $publicPage = visit(onlineBaseUrl().'/purchase-request')
        ->assertSee('采购申请')
        ->typeSlowly('textarea[placeholder]', $reason, 5)
        ->assertValue('textarea[placeholder]', $reason)
        ->fill('input[type="number"]', '-1')
        ->click('button[type="submit"]');

    $invalid = $publicPage->script('() => document.querySelector(\'input[type="number"]\').validity.rangeUnderflow');
    expect($invalid)->toBeTrue();

    $publicPage->fill('input[type="number"]', '1')
        ->click('button[type="submit"]')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    $procurement = visitOnlineAs('procurement', '/procurement/approvals')
        ->waitForText($reason)
        ->assertSee($reason);

    $rejected = $procurement->script(<<<JS
        async () => {
            window.confirm = () => true;
            const card = [...document.querySelectorAll('.approval-card')].find((candidate) => candidate.innerText.includes('{$runId}'));
            const trigger = card?.querySelector('.row-actions-menu-trigger');
            if (!trigger) return false;
            trigger.click();
            await new Promise((resolve) => setTimeout(resolve, 100));
            document.querySelector('.row-actions-menu-popup button.danger')?.click();
            await new Promise((resolve) => setTimeout(resolve, 1200));
            return true;
        }
    JS);
    expect($rejected)->toBeTrue();

    $admin = visitOnlineAs('admin', "/objects/requisition?q={$runId}")
        ->waitForText($reason);

    $deleted = $admin->script(<<<JS
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

    $admin->refresh()
        ->wait(1)
        ->assertDontSee($reason);
})->group('online', 'online-write', 'online-public', 'online-approval')
    ->skip(
        fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1'
            || getenv('ONLINE_REGRESSION_ALLOW_MUTATIONS') !== '1'
            || getenv('ONLINE_REGRESSION_RUN_ID') === false,
        'Online mutations require explicit opt-in and a run id.',
    );
