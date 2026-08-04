<?php

declare(strict_types=1);

it('keeps only the rejected purchase request audit trail from the completed mutation run', function (): void {
    $marker = (string) getenv('ONLINE_REGRESSION_RUN_ID');
    expect($marker)->toStartWith('PEST-');

    $page = visitOnlineAs('admin', "/objects/customer?q={$marker}")
        ->wait(2)
        ->assertDontSee($marker);

    $page->navigate(onlineBaseUrl().'/procurement/approvals')
        ->waitForText($marker)
        ->assertSee($marker)
        ->assertSee('已驳回')
        ->assertNoJavaScriptErrors();
})->group('online', 'online-retention', 'online-residue')
    ->skip(
        fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1'
            || ! str_starts_with((string) getenv('ONLINE_REGRESSION_RUN_ID'), 'PEST-'),
        'Residue verification requires an explicit PEST run id.',
    );
