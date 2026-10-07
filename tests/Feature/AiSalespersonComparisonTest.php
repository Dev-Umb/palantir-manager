<?php

namespace Tests\Feature;

use App\Actions\SyncXycMetadata;
use App\Ai\Tools\QueryObjectRecordsTool;
use App\Ai\XycDataAccess;
use App\Ai\XycDataAgent;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class AiSalespersonComparisonTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $salesA;

    private User $salesB;

    private ObjectRecord $projectA;

    private ObjectRecord $projectAZero;

    private ObjectRecord $projectB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-13 10:30:00', 'Asia/Taipei'));
        app(SyncXycMetadata::class)->handle();
        $this->admin = $this->user('管理员', 'admin');
        $this->salesA = $this->user('业务员甲', 'business');
        $this->salesB = $this->user('业务员乙', 'business');
        $this->projectA = $this->project($this->salesA, '业务员甲', 700);
        $this->projectAZero = $this->project($this->salesA, '业务员甲', 0);
        $this->projectB = $this->project($this->salesB, '业务员乙', 200);
        $this->project($this->admin, null, null);

    }

    public function test_recent_six_months_use_explicit_query_time_and_clamp_month_end(): void
    {
        $instructions = (string) XycDataAgent::make(user: $this->admin)->instructions();
        $this->assertStringContainsString('2026-03-13 至 2026-09-13 10:30:00', $instructions);
        $this->assertStringContainsString('累计 paid_amount 与 last_payment_date 不能证明期间回款', $instructions);
        $this->assertStringContainsString('不使用兼容 arrears 或合同金额减累计回款补算欠款', $instructions);
        $this->assertStringContainsString('无法核实（缺少相应数据）', $instructions);
        $this->travelTo(Carbon::parse('2026-08-31 10:30:00', 'Asia/Taipei'));
        $this->assertStringContainsString('2026-02-28 至 2026-08-31 10:30:00', (string) XycDataAgent::make(user: $this->admin)->instructions());
    }

    public function test_retired_shipments_cannot_be_queried(): void
    {
        $result = $this->query($this->admin, ['object' => 'shipment']);
        $this->assertFalse($result['ok']);
        $this->assertNotContains('shipment', array_column(app(XycDataAccess::class)->visibleObjects($this->admin), 'key'));
    }

    public function test_current_receivables_keep_older_projects_and_distinguish_null_from_zero(): void
    {
        $result = $this->query($this->admin, [
            'object' => 'project', 'group_by' => 'business_owner_user_id',
            'metrics' => [['op' => 'count', 'label' => '项目数'], ['op' => 'sum', 'field' => 'unpaid_amount', 'label' => '当前台账未回款']],
        ]);
        $this->assertTrue($result['ok']);
        $rows = collect($result['rows'])->keyBy('group');
        $this->assertSame(2, $rows[(string) $this->salesA->id]['项目数']);
        $this->assertSame(700, $rows[(string) $this->salesA->id]['当前台账未回款']);
        $this->assertSame(200, $rows[(string) $this->salesB->id]['当前台账未回款']);
        $this->assertNull($rows['未填写']['当前台账未回款']);
        $this->assertSame('missing', $result['data_quality'][0]['type']);
        $this->assertSame('unpaid_amount', $result['data_quality'][0]['field']);
        $details = $this->query($this->admin, ['object' => 'project', 'select' => ['id', 'unpaid_amount', 'updated_at']]);
        $row = collect($details['rows'])->firstWhere('id', $this->projectAZero->id);
        $this->assertEquals(0, $row['unpaid_amount']);
        $this->assertNotNull($row['updated_at']);
    }

    public function test_current_project_schema_exposes_cumulative_values_without_restoring_retired_ledger(): void
    {
        $objects = app(XycDataAccess::class)->visibleObjects($this->admin);
        $fields = collect($objects)->firstWhere('key', 'project')['fields'];
        $this->assertContains('paid_amount', array_column($fields, 'key'));
        $this->assertContains('last_payment_date', array_column($fields, 'key'));
        $this->assertNotContains('receivable', array_column($objects, 'key'));
        $this->assertFalse($this->query($this->admin, ['object' => 'receivable'])['ok']);
    }

    public function test_empty_project_query_does_not_fabricate_amounts(): void
    {
        $result = $this->query($this->admin, ['object' => 'project', 'filters' => [
            ['field' => 'name', 'operator' => 'eq', 'value' => '不存在的项目'],
        ], 'metrics' => [['op' => 'sum', 'field' => 'paid_amount', 'label' => '回款']]]);
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['record_count']);
    }

    public function test_salesperson_cannot_reach_other_owners_through_groups_filters_or_record_ids(): void
    {
        $result = $this->query($this->salesA, ['object' => 'project', 'group_by' => 'business_owner_user_id',
            'metrics' => [['op' => 'count', 'label' => '项目数']]]);
        $this->assertTrue($result['ok']);
        $this->assertNotContains((string) $this->salesB->id, array_column($result['rows'], 'group'));
        $this->assertContains((string) $this->salesA->id, array_column($result['rows'], 'group'));
        $foreign = $this->query($this->salesA, ['object' => 'project', 'filters' => [
            ['field' => 'business_owner_user_id', 'operator' => 'eq', 'value' => (string) $this->salesB->id],
        ]]);
        $this->assertSame([], $foreign['rows']);
        $this->assertFalse(app(XycDataAccess::class)->getRecord($this->salesA, 'project', $this->projectB->id)['ok']);
        $this->assertTrue(app(XycDataAccess::class)->getRecord($this->salesA, 'project', $this->projectA->id)['ok']);
    }

    private function user(string $name, string $role): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());

        return $user;
    }

    private function project(User $owner, ?string $manager, ?float $unpaid): ObjectRecord
    {
        $project = $this->record('project', [
            'name' => $manager.'测试项目', 'business_owner_user_id' => $manager ? (string) $owner->id : null,
            'contract_amount' => 10000, 'paid_amount' => 5000, 'unpaid_amount' => $unpaid,
        ], $owner);
        $project->forceFill(['created_at' => '2025-01-01 00:00:00'])->save();

        return $project;
    }

    private function record(string $object, array $payload, User $creator): ObjectRecord
    {
        return ObjectRecord::create([
            'business_object_id' => BusinessObject::where('key', $object)->value('id'),
            'code' => 'TEST-'.Str::uuid(), 'title' => $payload['name'] ?? '期间对比测试记录',
            'payload' => $payload, 'created_by' => $creator->id,
        ]);
    }

    private function query(User $user, array $input): array
    {
        return json_decode((string) (new QueryObjectRecordsTool($user))->handle(new Request($input)), true, flags: JSON_THROW_ON_ERROR);
    }
}
