<?php

namespace Tests\Feature;

use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PublicTeamLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_public_team_log_urls_accept_valid_and_reject_expired_or_tampered_signatures(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $valid = URL::temporarySignedRoute('team-logs.public.create', now()->addMinute());
        $this->get($valid)->assertOk();

        $expired = URL::temporarySignedRoute('team-logs.public.create', now()->subMinute());
        $this->get($expired)->assertForbidden();
        $this->get($valid.'&tampered=1')->assertForbidden();
    }

    public function test_public_team_log_view_throttle_is_enforced(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $url = URL::temporarySignedRoute('team-logs.public.create', now()->addMinute());
        foreach (range(1, 30) as $request) {
            $this->get($url)->assertOk();
        }
        $this->get($url)->assertTooManyRequests();
    }
}
