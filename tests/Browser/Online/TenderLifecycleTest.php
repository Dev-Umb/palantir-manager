<?php

declare(strict_types=1);

it('creates a customer and minute precise tender then converts it to a business project', function (): void {
    $runId = (string) getenv('ONLINE_REGRESSION_RUN_ID');
    $tenderEmail = (string) getenv('ONLINE_TENDER_EMAIL');
    $tenderName = "{$runId} 分钟级招投标";
    $customerName = "{$runId} 前期客户";

    $page = visit(onlineBaseUrl().'/login')
        ->fill('input[type="email"]', $tenderEmail)
        ->fill('input[type="password"]', onlinePassword())
        ->press('登录')
        ->wait(1)
        ->assertPathIs('/')
        ->navigate(onlineBaseUrl().'/objects/tender?mode=create')
        ->waitForText('新建招投标信息')
        ->fill('input[name="name"]', $tenderName)
        ->fill('.creatable-combo input', $customerName)
        ->fill('input[name="register_deadline"]', '2026-08-10T09:31')
        ->fill('input[name="purchase_deadline"]', '2026-08-11T10:32')
        ->fill('input[name="submit_deadline"]', '2026-08-12T14:33')
        ->fill('input[name="bid_open_at"]', '2026-08-13T15:34')
        ->fill('input[name="manager"]', $runId)
        ->assertValue('input[name="register_deadline"]', '2026-08-10T09:31')
        ->assertValue('input[name="purchase_deadline"]', '2026-08-11T10:32')
        ->assertValue('input[name="submit_deadline"]', '2026-08-12T14:33')
        ->assertValue('input[name="bid_open_at"]', '2026-08-13T15:34')
        ->click('.modal-panel button[type="submit"]')
        ->waitForText($tenderName)
        ->assertNoJavaScriptErrors();

    $record = $page->script(<<<JS
        () => {
            const row = [...document.querySelectorAll('.ag-row')]
                .find((candidate) => candidate.innerText.includes('{$runId}'));
            const href = row?.querySelector('a[href*="record="]')?.href;
            return href ? { id: new URL(href).searchParams.get('record') } : null;
        }
    JS);

    expect($record)->not->toBeNull()
        ->and($record['id'])->not->toBe('');

    $page->navigate(onlineBaseUrl()."/objects/tender?record={$record['id']}&mode=convert")
        ->waitForText('确认中标并流转')
        ->assertSee($tenderName);

    $assignee = $page->script(<<<'JS'
        () => {
            const select = document.querySelector('select[aria-label="接手业务员"]');
            const option = [...select.options].find((candidate) => candidate.value !== '');
            if (!option) return null;
            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            return { id: option.value, label: option.textContent };
        }
    JS);

    expect($assignee)->not->toBeNull();

    $page->click('.modal-panel button[type="submit"]')
        ->waitForText('已中标')
        ->assertSee('接手业务员')
        ->assertSee($assignee['label'])
        ->assertNoJavaScriptErrors();
})->group('online', 'online-write', 'online-tender')
    ->skip(
        fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1'
            || getenv('ONLINE_REGRESSION_ALLOW_MUTATIONS') !== '1'
            || getenv('ONLINE_REGRESSION_RUN_ID') === false
            || getenv('ONLINE_TENDER_EMAIL') === false,
        'Online tender mutations require explicit opt-in, a run id and a tender account.',
    );

it('changes the assignee from the won tender edit form', function (): void {
    $tenderEmail = (string) getenv('ONLINE_TENDER_EMAIL');
    $recordId = (string) getenv('ONLINE_TENDER_RECORD_ID');

    $page = visit(onlineBaseUrl().'/login')
        ->fill('input[type="email"]', $tenderEmail)
        ->fill('input[type="password"]', onlinePassword())
        ->press('登录')
        ->wait(1)
        ->assertPathIs('/')
        ->navigate(onlineBaseUrl()."/objects/tender?record={$recordId}&mode=edit")
        ->waitForText('保存')
        ->assertSee('接手业务员')
        ->assertNoJavaScriptErrors();

    $newAssignee = $page->script(<<<'JS'
        async () => {
            const field = [...document.querySelectorAll('label')]
                .find((label) => label.innerText.includes('接手业务员'));
            const select = field?.querySelector('select');
            if (select) {
                const current = select.value;
                const option = [...select.options]
                    .find((candidate) => candidate.value && candidate.value !== current);
                if (!option) return { label: null, current, options: [...select.options].map((candidate) => candidate.textContent) };
                select.value = option.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                return { label: option.textContent, current, options: [...select.options].map((candidate) => candidate.textContent) };
            }
            const current = field?.querySelector('.combo-trigger span')?.innerText;
            field?.querySelector('.combo-trigger')?.click();
            await new Promise((resolve) => setTimeout(resolve, 500));
            const options = [...document.querySelectorAll('.combo-option')];
            const option = options
                .find((candidate) => candidate.innerText.trim() && candidate.innerText.trim() !== current && candidate.innerText.trim() !== '未选择');
            if (!option) return { label: null, current, options: options.map((candidate) => candidate.innerText.trim()) };
            const label = option.innerText.trim();
            option.click();
            return { label, current, options: options.map((candidate) => candidate.innerText.trim()) };
        }
    JS);

    if ($newAssignee['label'] === null) {
        throw new RuntimeException(json_encode($newAssignee, JSON_UNESCAPED_UNICODE));
    }

    $page->click('.modal-panel button[type="submit"]')
        ->waitForText($newAssignee['label'])
        ->assertSee($newAssignee['label'])
        ->assertNoJavaScriptErrors();
})->group('online', 'online-write', 'online-tender')
    ->skip(
        fn (): bool => getenv('ONLINE_REGRESSION_ENABLED') !== '1'
            || getenv('ONLINE_REGRESSION_ALLOW_MUTATIONS') !== '1'
            || getenv('ONLINE_TENDER_EMAIL') === false
            || getenv('ONLINE_TENDER_RECORD_ID') === false,
        'Online tender assignee mutation requires explicit opt-in, a tender account and a record id.',
    );
