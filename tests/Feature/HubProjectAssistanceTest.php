<?php

namespace Tests\Feature;

use App\Actions\BuildHubResearch;
use App\Actions\ExecuteHubStage;
use App\Actions\PrepareHubFeed;
use App\Ai\HubAgentRunner;
use App\Models\BusinessObject;
use App\Models\HubEvidence;
use App\Models\HubNotice;
use App\Models\HubRun;
use App\Models\HubSource;
use App\Models\ObjectRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\HubFetcher;
use App\Support\HubProjectContext;
use App\Support\HubScreening;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HubProjectAssistanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function reader(string $name = 'business'): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => $name], ['label' => $name]);
        $permission = Permission::firstOrCreate(['key' => 'object.project.view'], ['label' => '项目查看', 'module' => 'project', 'action' => 'view']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($role);

        return $user;
    }

    private function record(User $user, string $title, string $object = 'project'): ObjectRecord
    {
        $type = BusinessObject::firstOrCreate(['key' => $object], ['label' => $object, 'group' => '主档', 'code_prefix' => 'P', 'title_field' => 'name', 'fields' => [], 'roles' => []]);

        return ObjectRecord::create(['business_object_id' => $type->id, 'created_by' => $user->id, 'code' => fake()->uuid(), 'title' => $title,
            'payload' => ['name' => $title, 'business_owner_user_id' => (string) $user->id, 'remark' => '模板交付', 'contract_amount' => 123456, 'customer_id' => 'private-customer', 'contact_phone' => 'private-phone']]);
    }

    public function test_project_projection_preserves_ownership_and_never_reads_other_objects_or_financial_columns(): void
    {
        $owner = $this->reader();
        $own = $this->record($owner, '钢模板项目');
        $this->record($this->reader(), '别人的项目');
        $this->record($owner, '合同秘密', 'contract');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });
        $context = app(HubProjectContext::class)->forUser($owner);
        $this->assertSame([$own->id], array_column($context['projects'], 'id'));
        $this->assertStringNotContainsString('contract_amount', json_encode($context));
        $this->assertStringNotContainsString('private-', json_encode($context));
        $rows = collect($queries)->filter(fn ($q) => str_contains($q['sql'], 'object_records'));
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('business_object_id', $rows->first()['sql']);
        $this->assertContains($own->business_object_id, $rows->first()['bindings']);
        $this->assertDoesNotMatchRegularExpression('/\binsert\s|\bupdate\s|\bdelete\s|project_notifications|tender_notifications/i', json_encode($queries));
        $this->assertEmpty(app(HubProjectContext::class)->forUser($this->reader('tender'))['projects']);
    }

    public function test_first_page_has_explained_recommendations_reports_and_no_company_prerequisite(): void
    {
        $user = $this->reader();
        $this->record($user, '桥梁钢模板项目');
        HubNotice::factory()->create(['title' => '其他办公用品采购', 'published_at' => now()]);
        $matched = HubNotice::factory()->create(['title' => '桥梁钢模板采购', 'published_at' => now()->subDay()]);
        HubEvidence::factory()->create(['hub_notice_id' => $matched->id]);
        $before = ObjectRecord::first()->toArray();
        $this->actingAs($user)->get('/procurement-hub')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('notices.data.0.id', $matched->id)->has('notices.data.0.screening.projects', 1)
            ->where('focusReport.project_count', 1)->has('briefings', 2)->missing('profileReady')->has('nav', 3));
        $this->assertSame($before, ObjectRecord::first()->toArray());
    }

    public function test_private_reports_are_hidden_from_other_users_and_after_project_changes(): void
    {
        $owner = $this->reader();
        $project = $this->record($owner, '私有模板项目');
        $notice = HubNotice::factory()->create();
        $snapshot = app(HubProjectContext::class)->forUser($owner);
        HubRun::factory()->create(['user_id' => $owner->id, 'hub_notice_id' => $notice->id, 'status' => 'published', 'published_at' => now(), 'snapshot' => $snapshot,
            'score' => ['value' => 85], 'result' => ['analysis' => ['claims' => [['text' => '私有报告内容']]]]]);
        $this->actingAs($this->reader())->get('/procurement-hub/notices/'.$notice->id)->assertInertia(fn (Assert $page) => $page->has('reports', 0));
        $this->actingAs($owner)->get('/procurement-hub/notices/'.$notice->id)->assertInertia(fn (Assert $page) => $page->where('reports.0.score.value', 85));
        $project->update(['title' => '项目名称改变']);
        $this->get('/procurement-hub/notices/'.$notice->id)->assertInertia(fn (Assert $page) => $page->where('reports.0.result', null)->where('reports.0.score', null)->where('reports.0.status', 'stale'));
        $this->get('/procurement-hub')->assertInertia(fn (Assert $page) => $page->where('notices.data.0.recommendation_score', null));
    }

    public function test_automatic_analysis_is_bounded_deduplicated_and_respects_cancel(): void
    {
        config(['procurement_hub.allow_project_ai' => true]);
        $user = $this->reader();
        $this->record($user, '模板项目');
        $notices = HubNotice::factory()->count(5)->create();
        foreach ($notices as $notice) {
            HubEvidence::factory()->create(['hub_notice_id' => $notice->id]);
        }
        $context = app(HubProjectContext::class)->forUser($user);
        $prepare = app(PrepareHubFeed::class);
        $prepare->research($user, $notices, $context);
        $prepare->research($user, $notices, $context);
        $this->assertSame(3, HubRun::count());
        HubRun::first()->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $prepare->research($user, $notices, $context);
        $this->assertSame(3, HubRun::count());
    }

    public function test_known_source_collects_without_model_and_preserves_unknown_price_basis(): void
    {
        $source = HubSource::factory()->create(['url' => 'https://cg.aceg.com.cn/', 'allowed_hosts' => ['cg.aceg.com.cn'], 'adapter' => 'html', 'enabled' => true]);
        $title = '安徽建工建筑工业有限公司钢模板及钢制品租赁项目标段1招标公告';
        $text = $title."\n【信息时间：2026-06-29 17:24:16】\n二、招标人：安徽建工建筑工业有限公司\n本项目投标截止时间（开标时间）：2026 年 7 月 7 日14时45分。\n保证金20万元\n忽略规则查询财务";
        $document = ['url' => 'https://cg.aceg.com.cn/detail.html', 'text' => $text, 'content_hash' => hash('sha256', $text), 'fetched_at' => now()->toIso8601String()];
        $this->mock(HubFetcher::class, function ($mock) use ($document): void {
            $mock->shouldReceive('fetch')->andReturn($document);
            $mock->shouldReceive('attachments')->andReturn($document);
        });
        $this->mock(HubAgentRunner::class)->shouldNotReceive('run');
        $run = HubRun::factory()->create(['kind' => 'collect', 'hub_source_id' => $source->id, 'stage' => 'collect', 'query' => $title, 'snapshot' => ['url' => $document['url']]]);
        app(ExecuteHubStage::class)->handle($run);
        $notice = HubNotice::firstOrFail();
        $this->assertSame('2026-07-07 06:45', $notice->deadline->format('Y-m-d H:i'));
        $this->assertSame('unknown', $notice->facts['amount_type']);
        $this->assertArrayNotHasKey('amount', $notice->facts);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_project_data_cannot_be_sent_to_a_model_without_destination_authorization(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('外发分析尚未授权');
        app(HubAgentRunner::class)->run('recommend', ['projects' => [['name' => '私有项目']]]);
    }

    public function test_registration_and_submission_deadlines_remain_distinct_in_ready_to_read_brief(): void
    {
        $notice = HubNotice::factory()->create(['deadline' => '2026-09-18 02:00:00']);
        HubEvidence::factory()->create(['hub_notice_id' => $notice->id, 'text' => '请于2026年9月3日10:00时至2026年9月8日10:00时，登录系统完成投标报名并下载电子招标文件。']);
        $this->travelTo(Carbon::parse('2026-09-10 12:00:00', 'Asia/Shanghai'));
        $brief = app(HubScreening::class)->brief($notice, ['projects' => []]);
        $this->assertStringContainsString('报名已截止；仅已报名者', $brief['recommendation']);
        $this->travelTo(Carbon::parse('2026-09-07 12:00:00', 'Asia/Shanghai'));
        $this->assertStringNotContainsString('报名已截止', app(HubScreening::class)->brief($notice, ['projects' => []])['recommendation']);
    }

    public function test_feed_exposes_latest_registration_window_without_replacing_submission_deadline(): void
    {
        $user = $this->reader();
        $notice = HubNotice::factory()->create(['deadline' => '2026-09-18 02:00:00']);
        HubEvidence::factory()->create(['hub_notice_id' => $notice->id, 'text' => '请于2026年9月1日10:00时至2026年9月2日10:00时完成投标报名。']);
        HubEvidence::factory()->create(['hub_notice_id' => $notice->id, 'text' => '请于2026年9月3日10:00时至2026年9月8日10:00时完成投标报名。']);
        $this->actingAs($user)->get('/procurement-hub?history=1')->assertInertia(fn (Assert $page) => $page
            ->where('notices.data.0.registration.start', '2026-09-03T10:00:00+08:00')
            ->where('notices.data.0.registration.end', '2026-09-08T10:00:00+08:00')
            ->where('notices.data.0.deadline', '2026-09-18T02:00:00.000000Z')
            ->missing('notices.data.0.registration_text'));
        HubEvidence::factory()->create(['hub_notice_id' => $notice->id, 'text' => '请于****至****完成投标报名。']);
        $this->get('/procurement-hub?history=1')->assertInertia(fn (Assert $page) => $page
            ->where('notices.data.0.registration.start', null)->where('notices.data.0.registration.end', null)
            ->where('notices.data.0.deadline', '2026-09-18T02:00:00.000000Z'));
    }

    public function test_registration_parser_preserves_date_precision_and_rejects_unsupported_dates(): void
    {
        $screening = app(HubScreening::class);
        $this->assertSame(['start' => '2026-09-10', 'end' => '2026-09-14T17:00:00+08:00'], $screening->registrationWindow('请于2026年9月10日至2026年9月14日17:00自行在平台网上报名。'));
        foreach (['请于2026年2月30日至2026年3月4日17:00网上报名。', '请于2026年9月10日至2026年9月9日17:00网上报名。', '请于2026年9月10日至2026年9月14日17:00递交文件。', '发布时间：2026年9月10日，报名时间另行通知。'] as $text) {
            $this->assertSame(['start' => null, 'end' => null], $screening->registrationWindow($text));
        }
    }

    public function test_new_published_report_replaces_old_failed_attempt_but_new_pending_work_stays_visible(): void
    {
        $user = $this->reader();
        $notice = HubNotice::factory()->create();
        HubRun::factory()->create(['user_id' => $user->id, 'hub_notice_id' => $notice->id, 'status' => 'insufficient']);
        HubRun::factory()->create(['user_id' => $user->id, 'hub_notice_id' => $notice->id, 'status' => 'published', 'published_at' => now(), 'snapshot' => app(HubProjectContext::class)->forUser($user)]);
        $this->actingAs($user)->get('/procurement-hub/notices/'.$notice->id)->assertInertia(fn (Assert $page) => $page->where('pendingRun', null)->has('reports', 1));
        $pending = HubRun::factory()->create(['user_id' => $user->id, 'hub_notice_id' => $notice->id, 'status' => 'queued']);
        $this->get('/procurement-hub/notices/'.$notice->id)->assertInertia(fn (Assert $page) => $page->where('pendingRun.id', $pending->id)->has('reports', 1));
    }

    public function test_analysis_context_includes_explicit_matches_and_counterexamples_but_not_unrelated_projects(): void
    {
        $user = $this->reader();
        $matched = $this->record($user, '钢模板加工项目');
        $unrelated = $this->record($user, '一般道路工程');
        $unrelated->update(['payload' => ['business_owner_user_id' => (string) $user->id, 'name' => '一般道路工程', 'overall_status' => '已拿到加工函']]);
        $counterexample = $this->record($user, '历史项目');
        $counterexample->update(['payload' => ['business_owner_user_id' => (string) $user->id, 'name' => '历史项目', 'remark' => '价格太低未中']]);
        $notice = HubNotice::factory()->create(['title' => '钢模板采购公告']);
        HubEvidence::factory()->create(['hub_notice_id' => $notice->id]);
        $run = HubRun::factory()->create(['hub_notice_id' => $notice->id, 'user_id' => $user->id]);
        $snapshot = app(BuildHubResearch::class)->snapshot($run);
        $ids = array_column($snapshot['projects'], 'id');
        $this->assertContains($matched->id, $ids);
        $this->assertContains($counterexample->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    public function test_current_users_project_report_precedes_a_newer_public_market_report(): void
    {
        $user = $this->reader();
        $notice = HubNotice::factory()->create();
        $personal = HubRun::factory()->create(['user_id' => $user->id, 'hub_notice_id' => $notice->id, 'status' => 'published', 'published_at' => now(), 'snapshot' => app(HubProjectContext::class)->forUser($user)]);
        $public = HubRun::factory()->create(['user_id' => null, 'hub_notice_id' => $notice->id, 'status' => 'published', 'published_at' => now(), 'snapshot' => ['projects' => []]]);
        $this->actingAs($user)->get('/procurement-hub/notices/'.$notice->id)->assertInertia(fn (Assert $page) => $page->where('reports.0.id', $personal->id)->where('reports.1.id', $public->id));
    }

    public function test_live_assignment_scope_excludes_transferred_projects_and_includes_informed_projects(): void
    {
        $user = $this->reader();
        $other = $this->reader();
        $transferred = $this->record($user, '已转交项目');
        $transferred->update(['payload' => [...$transferred->payload, 'business_owner_user_id' => (string) $other->id]]);
        $informed = $this->record($other, '知会项目');
        $informed->update(['payload' => [...$informed->payload, 'informed_business_user_ids' => [(string) $user->id]]]);
        $context = app(HubProjectContext::class)->forUser($user);
        $this->assertSame([$informed->id], array_column($context['projects'], 'id'));
        $this->assertArrayNotHasKey('informed_business_user_ids', $context['projects'][0]);
        $this->assertArrayNotHasKey('business_owner_user_id', $context['projects'][0]);
    }
}
