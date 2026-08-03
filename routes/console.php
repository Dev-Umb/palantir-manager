<?php

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
