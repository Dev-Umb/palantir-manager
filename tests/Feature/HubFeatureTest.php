<?php

namespace Tests\Feature;

use App\Models\HubNotice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HubFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function user(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => $role], ['label' => $role]));

        return $user;
    }

    public function test_retired_all_hub_pages_work_without_erp_reads_or_records(): void
    {
        $this->actingAs($this->user());
        foreach (['', '/batches', '/clients'] as $path) {
            $this->get('/procurement-hub'.$path)->assertOk();
        } foreach (['/research', '/follows', '/company', '/admin'] as $path) {
            $this->get('/procurement-hub'.$path)->assertNotFound();
        } $this->assertDatabaseCount('object_records', 0);
    }

    public function test_retired_role_boundaries_and_unauthenticated_redirect(): void
    {
        $this->actingAs($this->user());
        $this->actingAs($this->user('business'))->get('/procurement-hub')->assertOk();
        $this->get('/procurement-hub/clients')->assertForbidden();
        $this->get('/procurement-hub/company')->assertNotFound();
        $this->actingAs($this->user('finance'))->get('/procurement-hub')->assertForbidden();
    }

    public function test_retired_search_history_and_recommendation_visibility(): void
    {
        $this->actingAs($this->user());
        HubNotice::factory()->create();
        $this->get('/procurement-hub')->assertInertia(fn (Assert $page) => $page->component('Procurement/Index')->has('notices.data', 0)->missing('reports'));
    }

    public function test_retired_bookmarks_and_subscriptions_are_owned_and_show_updates(): void
    {
        $this->actingAs($this->user());
        $this->post('/procurement-hub/notices/1/bookmark')->assertNotFound();
        $this->post('/procurement-hub/subscriptions')->assertNotFound();
        $this->assertDatabaseCount('hub_bookmarks', 0);
        $this->assertDatabaseCount('hub_subscriptions', 0);
    }

    public function test_retired_source_validation_and_disabled_collection(): void
    {
        $this->actingAs($this->user());
        $this->post('/procurement-hub/sources')->assertNotFound();
        $this->post('/procurement-hub/sources/1/collect')->assertNotFound();
        $this->assertDatabaseCount('hub_sources', 0);
    }

    public function test_retired_company_draft_confirm_and_import_preview_are_separate(): void
    {
        $this->actingAs($this->user());
        $this->post('/procurement-hub/company')->assertNotFound();
        $this->post('/procurement-hub/imports/preview')->assertNotFound();
        $this->assertDatabaseCount('hub_companies', 0);
    }

    public function test_retired_bad_import_cannot_be_confirmed_and_other_admin_cannot_claim_preview(): void
    {
        $this->actingAs($this->user());
        $this->post('/procurement-hub/imports/1/confirm')->assertNotFound();
        $this->assertDatabaseCount('hub_imports', 0);
    }

    public function test_retired_research_dispatch_and_cancel_are_scoped(): void
    {
        $this->actingAs($this->user());
        $this->post('/procurement-hub/research')->assertNotFound();
        $this->post('/procurement-hub/runs/1/cancel')->assertNotFound();
        $this->assertDatabaseCount('hub_runs', 0);
    }

    public function test_retired_admin_withdrawal_is_audited_and_cannot_be_used_as_a_publish_bypass(): void
    {
        $this->actingAs($this->user());
        $this->post('/procurement-hub/runs/1/review')->assertNotFound();
        $this->assertDatabaseCount('hub_runs', 0);
    }
}
