<?php

use App\Jobs\SendReimbursementWechatNotice;
use App\Models\ReimbursementNotice;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('xyc:sync-project-notifications')
    ->dailyAt('01:00')
    ->withoutOverlapping();

Schedule::command('xyc:sync-tender-notifications')
    ->dailyAt('07:40')
    ->withoutOverlapping();

Schedule::command('xyc:sync-tender-notifications')
    ->dailyAt('13:40')
    ->withoutOverlapping();

Schedule::call(function (): void {
    ReimbursementNotice::where('delivery_status', 'sending')->where('claimed_at', '<', now()->subMinutes(5))->update(['delivery_status' => 'unknown', 'delivery_error' => 'worker_interrupted']);
    ReimbursementNotice::whereIn('delivery_status', ['pending', 'retry'])->where('attempts', '<', 3)->orderBy('id')->limit(100)->pluck('id')->each(function ($id): void {
        SendReimbursementWechatNotice::dispatch($id)->afterCommit();
    });
})->name('reimbursements:deliver-notices')->everyMinute()->withoutOverlapping();
