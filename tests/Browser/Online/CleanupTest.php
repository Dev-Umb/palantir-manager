<?php

declare(strict_types=1);

it('verifies the explicitly marked rejected request audit trail', function (): void {
    $marker = (string) getenv('ONLINE_CLEANUP_MARKER');
    expect($marker)->toStartWith('PEST-');

    $page = visitOnlineAs('admin', '/procurement/approvals')
        ->waitForText($marker);
    $page->assertSee('已驳回')
        ->assertNoJavaScriptErrors();
})->group('online', 'online-retention')
    ->skip(
        fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1'
            || getenv('ONLINE_REGRESSION_ALLOW_MUTATIONS') !== '1'
            || ! str_starts_with((string) getenv('ONLINE_CLEANUP_MARKER'), 'PEST-'),
        'Cleanup requires an explicit PEST marker.',
    );
