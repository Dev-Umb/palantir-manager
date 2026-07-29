<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $credential = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('login:credential:'.sha1("{$credential}|{$request->ip()}")),
                Limit::perMinute(20)->by('login:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('registration', fn (Request $request): array => [
            Limit::perMinute(3)->by('registration:ip:'.$request->ip()),
        ]);
        RateLimiter::for('public-requisition', fn (Request $request): array => [
            Limit::perMinute(5)->by('public-requisition:ip:'.$request->ip()),
        ]);
        RateLimiter::for('public-requisition-search', fn (Request $request): array => [
            Limit::perMinute(60)->by('public-requisition-search:ip:'.$request->ip()),
        ]);
        RateLimiter::for('public-team-log-view', fn (Request $request): array => [
            Limit::perMinute(30)->by('public-team-log-view:ip:'.$request->ip()),
        ]);
        RateLimiter::for('public-team-log', fn (Request $request): array => [
            Limit::perMinute(6)->by('public-team-log:ip:'.$request->ip()),
        ]);
        RateLimiter::for('ai-post', fn (Request $request): array => [
            Limit::perMinute(10)->by('ai:user:'.($request->user()?->id ?? 'guest')),
            Limit::perMinute(30)->by('ai:ip:'.$request->ip()),
        ]);
    }
}
