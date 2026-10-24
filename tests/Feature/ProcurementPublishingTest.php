<?php

namespace Tests\Feature;

use App\Actions\PublishProcurementBatch;
use App\Ai\AiArtifactFactory;
use App\Ai\Tools\PublishHtmlArtifactTool;
use App\Ai\Tools\QueryObjectRecordsTool;
use App\Ai\Tools\QueryProcurementTool;
use App\Ai\XycDataAgent;
use App\Jobs\CollectHubSource;
use App\Models\HubNotice;
use App\Models\Permission;
use App\Models\ProcurementBatch;
use App\Models\ProcurementClient;
use App\Models\ProcurementNotice;
use App\Models\ProcurementObservation;
use App\Models\Role;
use App\Models\User;
use App\Support\ProcurementPayload;
use App\Support\ProcurementQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Tools\Request as AiRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProcurementPublishingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function user(string $role = 'tender'): User
    {
        $u = User::factory()->create();
        $r = Role::firstOrCreate(['name' => $role], ['label' => $role]);
        $u->roles()->attach($r);

        return $u;
    }

    private function client(?User $u = null, string $token = 'test-collector-secret-12345678901234567890'): ProcurementClient
    {
        return ProcurementClient::create(['user_id' => ($u ?? $this->user())->id, 'name' => '测试采集端', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
    }

    private function payload(): array
    {
        return ['title' => '合成测试简报', 'date' => '2026-10-24', 'window' => '2026-10-23 15:00之后 — 2026-10-24 15:12', 'scope' => '合成物资', 'coverage' => [['测试平台', '公开记录', '1条', '仅测试']], 'exclusions' => [], 'limitations' => ['未验证资格'], 'items' => [['id' => 'sample', 'title' => '测试项目 / 模板', 'category' => '钢模板', 'priority' => 'P2', 'unit' => '测试采购单位', 'code' => 'TEST-001', 'published' => '2026-10-24（页面无时分）', 'registration' => '2026-10-30 10:00（文件获取；报名未单列）', 'deadline' => '2026-11-02 10:30', 'quantity' => '18套（暂估）', 'material' => '合成测试物资', 'contacts' => '测试联系人', 'judgment' => '需要核对', 'terms' => '合成测试条款', 'url' => 'https://example.com/list', 'platform' => '测试平台', 'status' => '纳入', 'issues' => '尺寸待核实', 'nature' => '公开询比', 'subtype' => '新购', 'invited' => false, 'framework' => false, 'evidence' => ['未上传.txt']]]];
    }

    private function publish(ProcurementClient $c, array $p, ?string $key = null): array
    {
        $a = app(PublishProcurementBatch::class);
        $v = $a->validate($c, $p);

        return $a->publish($c, $v['validation_id'], $key ?? (string) Str::uuid());
    }

    public function test_json_only_publish_preserves_fields_dates_issues_and_batch_metadata(): void
    {
        $u = $this->user();
        $c = $this->client($u);
        $p = $this->payload();
        $r = $this->publish($c, $p);
        $n = ProcurementNotice::firstOrFail();
        $this->assertSame($p['items'][0], $n->payload);
        $this->assertNull($n->registration_at);
        $this->assertSame('date', $n->dates['published']['precision']);
        $this->assertTrue($n->has_issues);
        $this->assertSame('P2', $n->priority);
        $this->assertSame($p, ProcurementBatch::find($r['batch_id'])->payload);
        $this->assertDatabaseCount('object_records', 0);
        $this->actingAs($u)->get('/procurement-hub')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Procurement/Index')->has('notices.data', 1)->where('totals.count', 1));
        $this->get('/procurement-hub/notices/'.$n->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Procurement/Show')->where('notice.payload.quantity', '18套（暂估）'));
    }

    public function test_retry_is_idempotent_and_changed_content_never_silently_overwrites(): void
    {
        $c = $this->client();
        $a = app(PublishProcurementBatch::class);
        $p = $this->payload();
        $v = $a->validate($c, $p);
        $key = (string) Str::uuid();
        $first = $a->publish($c, $v['validation_id'], $key);
        $this->assertSame($first, $a->publish($c, $v['validation_id'], $key));
        $this->assertSame('duplicate', $this->publish($c, $p)['results'][0]['status']);
        $p['items'][0]['quantity'] = '20套';
        $this->assertSame('pending_update', $this->publish($c, $p)['results'][0]['status']);
        $this->assertSame('18套（暂估）', ProcurementNotice::first()->payload['quantity']);
        $this->assertDatabaseCount('procurement_notices', 1);
    }

    public function test_same_key_different_content_is_conflict(): void
    {
        $c = $this->client();
        $key = (string) Str::uuid();
        $p = $this->payload();
        $this->publish($c, $p, $key);
        $p['items'][0]['quantity'] = '20套';
        $this->expectException(HttpException::class);
        $this->publish($c, $p, $key);
    }

    public function test_invited_visibility_applies_to_list_detail_filters_and_ai(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $p = $this->payload();
        $p['items'][0]['invited'] = true;
        $this->publish($this->client($owner), $p);
        $n = ProcurementNotice::first();
        $this->assertSame(0, app(ProcurementQuery::class)->result($other, [])['totals']['count']);
        $this->actingAs($other)->get('/procurement-hub/notices/'.$n->id)->assertNotFound();
        $r = $other->roles()->first();
        $permission = Permission::firstOrCreate(['key' => 'ai.harness.view'], ['label' => 'AI助手', 'module' => 'ai', 'action' => 'view']);
        $r->permissions()->syncWithoutDetaching([$permission->id]);
        $result = json_decode((new QueryProcurementTool($other->fresh()))->handle(new AiRequest([])), true);
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['totals']['count']);
        $this->assertSame(1, app(ProcurementQuery::class)->result($owner, [])['totals']['count']);
    }

    public function test_scoped_batch_results_and_revoked_credentials(): void
    {
        $c = $this->client();
        $r = $this->publish($c, $this->payload());
        $other = $this->client(null, 'other-collector-secret-12345678901234567890');
        try {
            app(PublishProcurementBatch::class)->result($other, $r['batch_id']);
            $this->fail('cross-client batch leak');
        } catch (ModelNotFoundException) {
        }
        $c->update(['revoked_at' => now()]);
        $this->expectException(HttpException::class);
        app(PublishProcurementBatch::class)->validate($c, $this->payload());
    }

    public function test_filters_keep_distinct_methods_and_unknown_registration_out_of_results(): void
    {
        $c = $this->client();
        $p = $this->payload();
        $this->publish($c, $p);
        $p['items'][0]['code'] = 'TEST-002';
        $p['items'][0]['nature'] = '公开竞争性谈判';
        $this->publish($c, $p);
        $u = User::find($c->user_id);
        $q = app(ProcurementQuery::class);
        $this->assertSame(1, $q->result($u, ['method' => '公开询比', 'start' => '2026-10-24', 'end' => '2026-10-24'])['totals']['count']);
        $this->assertSame(0, $q->result($u, ['time' => 'registration', 'start' => '2026-10-01'])['totals']['count']);
        $this->assertSame(2, $q->result($u, [])['totals']['count']);
    }

    public function test_invalid_payload_creates_no_visible_records_or_snapshot(): void
    {
        $c = $this->client();
        $p = $this->payload();
        $p['items'][0]['url'] = 'javascript:alert(1)';
        try {
            app(PublishProcurementBatch::class)->validate($c, $p);
            $this->fail('unsafe URL');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.url', $e->errors());
        }
        $this->assertDatabaseCount('procurement_batches', 0);
        $this->assertDatabaseCount('procurement_notices', 0);
    }

    public function test_real_customer_sample_when_explicitly_supplied(): void
    {
        $file = getenv('PROCUREMENT_SAMPLE_PATH');
        if (! $file) {
            $this->markTestSkipped('Explicit local customer sample not supplied.');
        }
        $p = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $c = $this->client();
        $rpc = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'validate_procurement_batch', 'arguments' => ['payload' => $p]]];
        $validated = $this->withToken('test-collector-secret-12345678901234567890')->postJson('/mcp/procurement', $rpc)->assertOk()->json('result.structuredContent');
        $rpc['params'] = ['name' => 'publish_procurement_batch', 'arguments' => ['validation_id' => $validated['validation_id'], 'idempotency_key' => (string) Str::uuid()]];
        $r = $this->postJson('/mcp/procurement', $rpc)->assertOk()->json('result.structuredContent');
        $rpc['params'] = ['name' => 'get_procurement_batch', 'arguments' => ['batch_id' => $r['batch_id']]];
        $this->postJson('/mcp/procurement', $rpc)->assertOk()->assertJsonPath('result.structuredContent.counts.created', 14);
        $this->assertSame(14, $r['counts']['created']);
        $this->assertDatabaseCount('procurement_notices', 14);
        $this->assertSame(8, ProcurementNotice::where('has_issues', true)->count());
        $this->assertSame(6, ProcurementNotice::distinct()->count('method'));
        $this->assertSame(14, $this->publish($c, $p)['counts']['duplicate']);
        $u = User::find($c->user_id);
        $this->actingAs($u)->get('/procurement-hub')->assertOk()->assertInertia(fn (Assert $a) => $a->has('notices.data', 14));
    }

    public function test_mcp_http_tool_is_authenticated_and_roundtrips(): void
    {
        $c = $this->client();
        $rpc = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'validate_procurement_batch', 'arguments' => ['payload' => $this->payload()]]];
        $this->postJson('/mcp/procurement', $rpc)->assertUnauthorized();
        $response = $this->withToken('test-collector-secret-12345678901234567890')->postJson('/mcp/procurement', $rpc)->assertOk();
        $data = $response->json('result.structuredContent');
        $this->assertArrayHasKey('validation_id', $data);
        $rpc['params'] = ['name' => 'publish_procurement_batch', 'arguments' => ['validation_id' => $data['validation_id'], 'idempotency_key' => (string) Str::uuid()]];
        $response = $this->postJson('/mcp/procurement', $rpc)->assertOk();
        $this->assertSame('completed', $response->json('result.structuredContent.status'));
        $this->assertDatabaseCount('procurement_notices', 1);
    }

    public function test_old_routes_are_retired_and_old_jobs_do_not_execute(): void
    {
        $this->actingAs($this->user('admin'));
        foreach (['/research', '/follows', '/company', '/admin'] as $p) {
            $this->get('/procurement-hub'.$p)->assertNotFound();
        }
        Http::preventStrayRequests();
        (new CollectHubSource(1))->handle();
        $this->assertDatabaseCount('hub_runs', 0);
    }

    public function test_same_identity_in_one_batch_is_an_observation_not_a_second_creation(): void
    {
        $p = $this->payload();
        $p['items'][] = [...$p['items'][0], 'id' => 'second', 'quantity' => '99套'];
        $r = $this->publish($this->client(), $p);
        $this->assertSame(['created' => 1, 'pending_update' => 1], $r['counts']);
        $this->assertDatabaseCount('procurement_notices', 1);
        $this->assertSame('18套（暂估）', ProcurementNotice::first()->payload['quantity']);
    }

    public function test_cross_client_identity_review_does_not_reveal_existing_record_id(): void
    {
        $this->publish($this->client(), $this->payload());
        $other = $this->client(null, 'another-collector-123456789012345678901234567');
        $r = $this->publish($other, $this->payload());
        $this->assertSame('identity_review', $r['results'][0]['status']);
        $this->assertNull($r['results'][0]['record_id']);
        $this->actingAs(User::find($other->user_id))->get('/procurement-hub/batches/'.$r['batch_id'])
            ->assertOk()->assertInertia(fn (Assert $a) => $a->missing('observations.0.notice_id'));
    }

    public function test_expired_validation_and_expired_client_cannot_publish(): void
    {
        $c = $this->client();
        $a = app(PublishProcurementBatch::class);
        $v = $a->validate($c, $this->payload());
        ProcurementBatch::find($v['validation_id'])->update(['expires_at' => now()->subMinute()]);
        try {
            $a->publish($c, $v['validation_id'], (string) Str::uuid());
            $this->fail('expired snapshot accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $c->update(['expires_at' => now()->subMinute()]);
        try {
            $a->validate($c, $this->payload());
            $this->fail('expired client accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('procurement_notices', 0);
    }

    public function test_admin_accepts_changed_observation_but_never_widens_invited_visibility(): void
    {
        $c = $this->client();
        $p = $this->payload();
        $p['items'][0]['invited'] = true;
        $this->publish($c, $p);
        $p['items'][0]['invited'] = false;
        $p['items'][0]['quantity'] = '20套';
        $r = $this->publish($c, $p);
        $o = ProcurementObservation::where('batch_id', $r['batch_id'])->firstOrFail();
        $this->actingAs(User::find($c->user_id))->post('/procurement-hub/observations/'.$o->id.'/accept')->assertForbidden();
        $this->actingAs($this->user('admin'))->post('/procurement-hub/observations/'.$o->id.'/accept')->assertRedirect();
        $n = ProcurementNotice::first();
        $this->assertTrue($n->invited);
        $this->assertSame('20套', $n->payload['quantity']);
        $this->post('/procurement-hub/observations/'.$o->id.'/accept')->assertConflict();
        $this->assertDatabaseCount('object_records', 0);
    }

    public function test_date_only_deadline_is_filterable_without_inventing_time_and_search_is_literal(): void
    {
        $c = $this->client();
        $p = $this->payload();
        $p['items'][0]['deadline'] = '2026-11-02';
        $this->publish($c, $p);
        $q = app(ProcurementQuery::class);
        $u = User::find($c->user_id);
        $this->assertSame(1, $q->result($u, ['time' => 'deadline', 'start' => '2026-11-02', 'end' => '2026-11-02'])['totals']['count']);
        $this->assertNull(ProcurementNotice::first()->deadline_at);
        $this->assertSame(0, $q->result($u, ['q' => '%'])['totals']['count']);
        $this->assertSame(1, $q->result($u, ['q' => 'TEST-001'])['totals']['count']);
        $this->assertSame(1, $q->result($u, ['q' => '测试采购单位'])['totals']['count']);
    }

    public function test_retirement_migration_only_clears_legacy_hub_data(): void
    {
        $u = $this->user('admin');
        $c = $this->client($u);
        $this->publish($c, $this->payload());
        HubNotice::factory()->create();
        DB::table('hub_companies')->insert(['kind' => 'self', 'name' => '旧企业资料', 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $migration = require database_path('migrations/2026_10_24_084225_retire_legacy_procurement_hub_data.php');
        $migration->up();
        foreach (['hub_notices', 'hub_companies', 'hub_runs', 'hub_sources'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('users', ['id' => $u->id]);
        $this->assertDatabaseCount('procurement_notices', 1);
    }

    public function test_ai_reads_authorized_sources_and_is_registered_without_removing_erp_tools(): void
    {
        $u = $this->user('admin');
        $this->publish($this->client($u), $this->payload());
        $permission = Permission::firstOrCreate(['key' => 'ai.harness.view'], ['label' => 'AI助手', 'module' => 'ai', 'action' => 'view']);
        $u->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);
        $u = $u->fresh();
        $agent = new XycDataAgent($u, app(PublishHtmlArtifactTool::class));
        $tools = collect($agent->tools());
        $this->assertTrue($tools->contains(fn ($t) => $t instanceof QueryProcurementTool));
        $this->assertTrue($tools->contains(fn ($t) => $t instanceof QueryObjectRecordsTool));
        $r = json_decode((new QueryProcurementTool($u))->handle(new AiRequest(['method' => '公开询比'])), true);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['record_count']);
        $projection = app(AiArtifactFactory::class)->fromToolResult('query_procurement', $r);
        $this->assertSame('procurement', $projection['sources'][0]['object_key']);
        $this->assertSame(1, $r['totals']['count']);
        $this->assertSame('https://example.com/list', $r['notices']['data'][0]['payload']['url']);
        $this->assertSame('尺寸待核实', $r['notices']['data'][0]['payload']['issues']);
        $u->roles()->detach();
        $this->assertFalse(json_decode((new QueryProcurementTool($u))->handle(new AiRequest([])), true)['ok']);
    }

    public function test_daily_local_id_change_is_not_a_business_update(): void
    {
        $c = $this->client();
        $p = $this->payload();
        $this->publish($c, $p);
        $p['items'][0]['id'] = 'next-day-local-id';
        $this->assertSame('duplicate', $this->publish($c, $p)['results'][0]['status']);
    }

    public function test_packaged_synthetic_example_obeys_server_contract(): void
    {
        $p = json_decode(file_get_contents(resource_path('skills/procurement-publish/references/example.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($p, app(ProcurementPayload::class)->validate($p));
    }
}
