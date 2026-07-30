<?php

declare(strict_types=1);

it('finds no records left by the completed mutation run', function (): void {
    $marker = (string) getenv('ONLINE_REGRESSION_RUN_ID');
    expect($marker)->toStartWith('PEST-');

    $page = visitOnlineAs('admin', "/objects/customer?q={$marker}")
        ->wait(2)
        ->assertDontSee($marker);

    $page->navigate(onlineBaseUrl().'/procurement/approvals')
        ->wait(1)
        ->assertDontSee($marker)
        ->navigate(onlineBaseUrl()."/objects/requisition?q={$marker}")
        ->wait(15)
        ->assertDontSee($marker);
})->group('online', 'online-cleanup', 'online-residue')
    ->skip(
        fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1'
            || ! str_starts_with((string) getenv('ONLINE_REGRESSION_RUN_ID'), 'PEST-'),
        'Residue verification requires an explicit PEST run id.',
    );
