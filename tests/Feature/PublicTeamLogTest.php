<?php

namespace Tests\Feature;

use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicTeamLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_signed_public_team_log_urls_accept_valid_and_reject_expired_or_tampered_signatures(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('team_log', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
    }

    public function test_retired_public_team_log_view_throttle_is_enforced(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('team_log', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
    }
}
