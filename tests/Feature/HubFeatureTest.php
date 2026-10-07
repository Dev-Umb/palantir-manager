<?php

namespace Tests\Feature;

use App\Jobs\RunHubStage;
use App\Models\HubBookmark;
use App\Models\HubCompany;
use App\Models\HubImport;
use App\Models\HubNotice;
use App\Models\HubRun;
use App\Models\HubSource;
use App\Models\HubSubscription;
use App\Models\Role;
use App\Models\User;
use App\Support\HubProjectContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

    public function test_all_hub_pages_work_without_erp_reads_or_records(): void
    {
        $user = $this->user();
        $notice = HubNotice::factory()->create();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        foreach (['', '/research', '/follows', '/company', '/admin', '/notices/'.$notice->id] as $path) {
            $this->actingAs($user)->get('/procurement-hub'.$path)->assertOk();
        }
        $this->assertDoesNotMatchRegularExpression('/business_objects|object_records|project_notifications|tender_notifications/i', implode("\n", $queries));
    }

    public function test_role_boundaries_and_unauthenticated_redirect(): void
    {
        $this->get('/procurement-hub')->assertRedirect('/login');
        foreach (['business', 'tender'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->get('/procurement-hub')->assertOk();
            $this->get('/procurement-hub/admin')->assertForbidden();
            $this->get('/procurement-hub/company')->assertForbidden();
            $this->post('/procurement-hub/company', [])->assertForbidden();
        }
        $this->actingAs($this->user('finance'))->get('/procurement-hub')->assertForbidden();
    }

    public function test_search_history_and_recommendation_visibility(): void
    {
        $this->withoutExceptionHandling();
        $user = $this->user('business');
        $expired = HubNotice::factory()->create(['title' => '过期采购', 'deadline' => now()->subDay()]);
        $notice = HubNotice::factory()->create(['title' => '钢模板采购']);
        HubRun::factory()->create(['hub_notice_id' => $notice->id, 'status' => 'running', 'score' => ['value' => 99], 'result' => ['secret' => '未经审计']]);
        $this->actingAs($user)->get('/procurement-hub?sort=recommended')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ProcurementHub/Index')->has('notices.data', 1)->where('notices.data.0.recommendation_score', null));
        $this->get('/procurement-hub?history=1&q='.urlencode('过期'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('notices.data', 1)->where('notices.data.0.id', $expired->id));
        $this->get('/procurement-hub/notices/'.$notice->id)->assertDontSee('未经审计');
        HubRun::factory()->create(['user_id' => $user->id, 'snapshot' => app(HubProjectContext::class)->forUser($user), 'hub_notice_id' => $notice->id, 'status' => 'published', 'published_at' => now(), 'score' => ['value' => 72]]);
        $this->get('/procurement-hub?sort=recommended')->assertOk()->assertInertia(fn (Assert $page) => $page->where('notices.data.0.recommendation_score', 72));
    }

    public function test_bookmarks_and_subscriptions_are_owned_and_show_updates(): void
    {
        $user = $this->user('business');
        $other = $this->user('business');
        $notice = HubNotice::factory()->create();
        $subscription = HubSubscription::factory()->create(['user_id' => $other->id]);
        $this->actingAs($user)->post('/procurement-hub/notices/'.$notice->id.'/bookmark')->assertRedirect();
        $this->assertSame(1, HubBookmark::where('user_id', $user->id)->count());
        $this->delete('/procurement-hub/subscriptions/'.$subscription->id)->assertForbidden();
        $this->post('/procurement-hub/subscriptions/'.$subscription->id.'/read')->assertForbidden();
        $this->get('/procurement-hub/follows')->assertInertia(fn (Assert $page) => $page->has('notices', 1)->has('subscriptions', 0));
        $this->post('/procurement-hub/subscriptions', ['name' => '模板订阅', 'filters' => ['q' => '钢模板']])->assertRedirect();
        $this->travel(2)->minutes();
        $notice->touch();
        $this->get('/procurement-hub/follows')->assertInertia(fn (Assert $page) => $page->where('notices.0.has_update', true)->has('subscriptions.0.matches', 1));
        $this->get('/procurement-hub/notices/'.$notice->id)->assertOk();
        $this->get('/procurement-hub/follows')->assertInertia(fn (Assert $page) => $page->where('notices.0.has_update', false));
    }

    public function test_source_validation_and_disabled_collection(): void
    {
        $this->actingAs($this->user());
        $source = HubSource::factory()->create();
        $this->post('/procurement-hub/sources/'.$source->id.'/collect')->assertStatus(422);
        $data = ['name' => '公开来源', 'url' => 'https://outside.example.com/', 'allowed_hosts' => ['example.com'], 'enabled' => true, 'adapter' => 'html', 'interval_minutes' => 360];
        $this->post('/procurement-hub/sources', $data)->assertSessionHasErrors('url');
        $data['url'] = 'https://example.com/';
        $data['adapter'] = 'unverified';
        $this->post('/procurement-hub/sources', $data)->assertSessionHasErrors('enabled');
        Queue::fake();
        $data = [...$data, 'url' => 'https://projects.worldbank.org/', 'allowed_hosts' => ['projects.worldbank.org', 'search.worldbank.org'], 'adapter' => 'worldbank'];
        $this->post('/procurement-hub/sources', $data)->assertSessionHasNoErrors()->assertRedirect();
        $expanded = HubSource::where('adapter', 'worldbank')->firstOrFail();
        $this->post('/procurement-hub/sources/'.$expanded->id.'/collect')->assertRedirect();
        $this->actingAs($this->user('sales'))->post('/procurement-hub/sources/'.$expanded->id.'/collect')->assertForbidden();
    }

    public function test_company_draft_confirm_and_import_preview_are_separate(): void
    {
        Queue::fake();
        $user = $this->user();
        $this->actingAs($user)->post('/procurement-hub/company', ['kind' => 'capability', 'name' => '模板能力', 'data' => ['product' => '钢模板']])->assertRedirect();
        $company = HubCompany::firstOrFail();
        $this->assertSame('draft', $company->status);
        $this->post('/procurement-hub/company/'.$company->id.'/confirm')->assertRedirect();
        $this->assertSame('confirmed', $company->fresh()->status);
        $csv = UploadedFile::fake()->createWithContent('history.csv', "kind,name,product,outcome\nhistory,某项目,钢模板,won\n");
        $this->post('/procurement-hub/imports/preview', ['file' => $csv])->assertRedirect();
        $this->assertSame(1, HubCompany::count());
        $import = HubImport::firstOrFail();
        $this->assertSame([], $import->errors);
        $this->post('/procurement-hub/imports/'.$import->id.'/confirm')->assertRedirect();
        $this->post('/procurement-hub/imports/'.$import->id.'/confirm')->assertRedirect();
        $this->assertSame(2, HubCompany::count());
        $this->assertSame('won', HubCompany::where('kind', 'history')->first()->data['outcome']);
        $this->assertDatabaseCount('object_records', 0);
        Queue::assertNothingPushed();
    }

    public function test_bad_import_cannot_be_confirmed_and_other_admin_cannot_claim_preview(): void
    {
        $owner = $this->user();
        $import = HubImport::factory()->create(['user_id' => $owner->id, 'rows' => [['kind' => 'history', 'name' => '样本', 'data' => []]], 'errors' => ['格式错误']]);
        $this->actingAs($owner)->post('/procurement-hub/imports/'.$import->id.'/confirm')->assertSessionHasErrors('file');
        $this->actingAs($this->user())->post('/procurement-hub/imports/'.$import->id.'/confirm')->assertForbidden();
        $this->assertSame(0, HubCompany::count());
    }

    public function test_research_dispatch_and_cancel_are_scoped(): void
    {
        Queue::fake();
        $user = $this->user('business');
        $this->actingAs($user)->post('/procurement-hub/research', ['query' => '钢模板'])->assertRedirect('/procurement-hub/research');
        Queue::assertPushed(RunHubStage::class);
        $run = HubRun::firstOrFail();
        $this->actingAs($this->user('business'))->post('/procurement-hub/runs/'.$run->id.'/cancel')->assertForbidden();
        $this->actingAs($user)->post('/procurement-hub/runs/'.$run->id.'/cancel')->assertRedirect();
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->post('/procurement-hub/runs/'.$run->id.'/retry')->assertRedirect();
        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_admin_withdrawal_is_audited_and_cannot_be_used_as_a_publish_bypass(): void
    {
        Queue::fake();
        $notice = HubNotice::factory()->create();
        $run = HubRun::factory()->create(['hub_notice_id' => $notice->id, 'status' => 'published', 'published_at' => now(), 'score' => ['value' => 70], 'result' => ['analysis' => ['claims' => [['text' => '待撤回结果']]]]]);
        $this->actingAs($this->user('business'))->post('/procurement-hub/runs/'.$run->id.'/review', ['action' => 'withdraw', 'note' => '口径错误'])->assertForbidden();
        $this->actingAs($this->user())->post('/procurement-hub/runs/'.$run->id.'/review', ['action' => 'publish', 'note' => '直接通过'])->assertSessionHasErrors('action');
        $this->post('/procurement-hub/runs/'.$run->id.'/review', ['action' => 'research', 'note' => '口径错误'])->assertRedirect();
        $this->assertSame('withdrawn', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->published_at);
        $this->assertSame('口径错误', $run->fresh()->audit['manual_reviews'][0]['note']);
        $this->assertNull($run->fresh()->score);
        $this->assertSame(2, HubRun::count());
        $this->get('/procurement-hub/notices/'.$notice->id)->assertInertia(fn (Assert $page) => $page->has('reports', 0));
    }
}
