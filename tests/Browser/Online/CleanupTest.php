<?php

declare(strict_types=1);

it('removes only explicitly marked regression records', function (): void {
    $marker = (string) getenv('ONLINE_CLEANUP_MARKER');
    expect($marker)->toStartWith('PEST-');

    $page = visitOnlineAs('admin', '/procurement/approvals')
        ->waitForText($marker);

    $code = $page->script(<<<JS
        () => [...document.querySelectorAll('.approval-card')]
            .find((card) => card.innerText.includes('{$marker}'))
            ?.querySelector('.mono')
            ?.innerText
    JS);
    expect($code)->not->toBeNull();

    $page->navigate(onlineBaseUrl()."/objects/requisition?q={$code}")
        ->wait(15);

    $deleted = $page->script(<<<JS
        async () => {
            window.confirm = () => true;
            const dataRow = [...document.querySelectorAll('.ag-row')]
                .find((row) => row.innerText.includes('{$marker}'));
            const rowIndex = dataRow?.getAttribute('row-index');
            const trigger = dataRow?.querySelector('.row-actions-menu-trigger');
            if (!trigger) {
                return {
                    count: 0,
                    rowIndex,
                    rows: [...document.querySelectorAll('.ag-row')].map((row) => ({
                        index: row.getAttribute('row-index'),
                        text: row.innerText.slice(0, 120),
                        menu: Boolean(row.querySelector('.row-actions-menu-trigger')),
                    })),
                };
            }
            trigger.click();
            await new Promise((resolve) => setTimeout(resolve, 100));
            document.querySelector('.row-actions-menu-popup button.danger')?.click();
            await new Promise((resolve) => setTimeout(resolve, 1200));
            return { count: 1 };
        }
    JS);

    if (($deleted['count'] ?? 0) === 0) {
        throw new RuntimeException(json_encode($deleted, JSON_UNESCAPED_UNICODE));
    }
    $page->refresh()
        ->wait(1)
        ->assertDontSee($marker);
})->group('online', 'online-cleanup')
    ->skip(
        fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1'
            || getenv('ONLINE_REGRESSION_ALLOW_MUTATIONS') !== '1'
            || ! str_starts_with((string) getenv('ONLINE_CLEANUP_MARKER'), 'PEST-'),
        'Cleanup requires an explicit PEST marker.',
    );
