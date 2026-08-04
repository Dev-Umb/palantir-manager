<?php

namespace Tests\Feature;

use App\Actions\SyncXycMetadata;
use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompanyOperationsCockpitTest extends TestCase
{
    use RefreshDatabase;

    private int $recordSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(SyncXycMetadata::class)->handle();
    }

    public function test_admin_sees_read_only_company_metrics_and_existing_fact_charts(): void
    {
        $admin = $this->userWithRole('admin');

        $this->record('project', ['name' => '未知状态项目', 'overall_status' => '未知状态']);
        foreach (['投标中', '已中标', '已拿到加工函', '合同签署', '已完成'] as $status) {
            $this->record('project', ['name' => "{$status}项目", 'overall_status' => $status]);
        }

        $this->record('contract', ['amount' => 5800000, 'status' => '已签署']);
        $receivable = $this->record('receivable', [
            'contract_amount' => 5800000,
            'occurred_amount' => 4200000,
            'reconciled_amount' => 3600000,
            'paid_amount' => 2900000,
        ]);
        $this->record('invoice', ['amount' => 3600000, 'status' => '已开票']);

        foreach (['已递交', '已递交', '已中标', '已中标', '已中标', '未中标', '未中标', '未中标'] as $status) {
            $this->record('tender', ['status' => $status, 'budget_amount' => 1000000]);
        }

        $this->record('work_order', ['status' => '生产中', 'production_qty_ton' => 600, 'weight' => 900]);
        $this->record('work_order', ['status' => '已完成', 'production_qty_ton' => 520, 'weight' => 780]);

        foreach ([
            [80, '2026-03-08'],
            [105, '2026-04-08'],
            [120, '2026-05-08'],
            [95, '2026-06-08'],
            [140, '2026-07-08'],
            [150, '2026-08-03'],
            [30, null],
            [-5, '2026-08-04'],
            ['无法解析', '2026-08-04'],
        ] as [$quantity, $date]) {
            $this->record('shipment', ['qty_ton' => $quantity, 'ship_date' => $date]);
        }

        $timestampsBefore = $this->recordTimestamps();
        $recordCountBefore = ObjectRecord::count();
        $objectCountBefore = BusinessObject::count();
        $auditCountBefore = AuditLog::count();

        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('cockpit.meta.scope', '公司全量')
                ->has('cockpit.kpis', 4)
                ->where('cockpit.kpis.0.key', 'occurred_amount')
                ->where('cockpit.kpis.0.value', fn (mixed $value): bool => (float) $value === 4200000.0)
                ->where('cockpit.kpis.1.key', 'collection_rate')
                ->where('cockpit.kpis.1.value', fn (mixed $value): bool => (float) $value === 69.0)
                ->where('cockpit.kpis.2.key', 'tender_win_rate')
                ->where('cockpit.kpis.2.value', 37.5)
                ->where('cockpit.kpis.3.key', 'current_debt')
                ->where('cockpit.kpis.3.value', fn (mixed $value): bool => (float) $value === 1300000.0)
                ->where('cockpit.panels.cash_flow.series.0.label', '合同金额')
                ->where('cockpit.panels.cash_flow.series.0.value', fn (mixed $value): bool => (float) $value === 5800000.0)
                ->where('cockpit.panels.project_status.active_total', 4)
                ->where('cockpit.panels.project_status.completed_count', 1)
                ->where('cockpit.panels.project_status.unmaintained_count', 1)
                ->where('cockpit.panels.tender_pipeline.records_count', 8)
                ->where('cockpit.panels.production_delivery.production.total_ton', fn (mixed $value): bool => (float) $value === 1120.0)
                ->where('cockpit.panels.production_delivery.shipment.total_ton', fn (mixed $value): bool => (float) $value === 720.0)
                ->where('cockpit.panels.production_delivery.shipment.trend_coverage', ['valid' => 6, 'total' => 7])
                ->where('cockpit.panels.production_delivery.shipment.invalid_quantity_count', 2)
                ->where('cockpit.panels.production_delivery.shipment.undated_ton', fn (mixed $value): bool => (float) $value === 30.0)
                ->has('cockpit.panels.production_delivery.shipment.monthly', 6)
                ->where('cockpit.panels.production_delivery.shipment.monthly.5.ton', fn (mixed $value): bool => (float) $value === 150.0)
                ->has('cockpit.project_progresses', 6));

        $this->assertSame($recordCountBefore, ObjectRecord::count());
        $this->assertSame($objectCountBefore, BusinessObject::count());
        $this->assertSame($auditCountBefore, AuditLog::count());
        $this->assertSame($timestampsBefore, $this->recordTimestamps());
        $this->assertModelExists($receivable);
    }

    public function test_dashboard_only_user_receives_a_safe_empty_cockpit(): void
    {
        $basic = $this->userWithRole('basic');
        $this->record('project', ['name' => '不可见项目', 'overall_status' => '投标中']);
        $this->record('receivable', ['occurred_amount' => 9999999, 'paid_amount' => 9999999]);

        $this->actingAs($basic)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cockpit.meta.scope', '我的可见范围')
                ->has('cockpit.kpis', 0)
                ->where('cockpit.panels', [])
                ->where('cockpit.project_progress', null)
                ->has('cockpit.project_progresses', 0)
                ->has('recentProjects', 0));
    }

    public function test_business_user_project_chart_is_limited_to_owned_projects(): void
    {
        $owner = $this->userWithRole('business');
        $other = $this->userWithRole('business');
        $this->record('project', [
            'name' => '我的项目',
            'business_owner_user_id' => (string) $owner->id,
            'overall_status' => '已中标',
        ], $owner);
        $this->record('project', [
            'name' => '他人项目',
            'business_owner_user_id' => (string) $other->id,
            'overall_status' => '合同签署',
        ], $other);
        $this->record('receivable', [
            'occurred_amount' => 5000000,
            'paid_amount' => 5000000,
        ], $other);

        $this->actingAs($owner)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cockpit.meta.scope', '我的可见范围')
                ->where('cockpit.panels.project_status.records_count', 1)
                ->where('cockpit.panels.project_status.active_total', 1)
                ->where('cockpit.panels.project_status.statuses.1.count', 1)
                ->has('cockpit.project_progresses', 1)
                ->where('cockpit.kpis', fn (Collection $kpis): bool => $kpis
                    ->pluck('key')
                    ->intersect(['occurred_amount', 'collection_rate', 'current_debt'])
                    ->isEmpty()));
    }

    public function test_zero_finance_base_is_not_reported_as_zero_percent(): void
    {
        $finance = $this->userWithRole('finance');
        $this->record('receivable', [
            'contract_amount' => 0,
            'occurred_amount' => 0,
            'paid_amount' => 0,
        ]);

        $this->actingAs($finance)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cockpit.kpis', function (Collection $kpis): bool {
                    $collectionRate = $kpis->firstWhere('key', 'collection_rate');

                    return $collectionRate['value'] === null
                        && $collectionRate['hint'] === '分母为 0，暂不可计算';
                }));
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }

    private function record(string $objectKey, array $payload, ?User $creator = null): ObjectRecord
    {
        $this->recordSequence++;
        $object = BusinessObject::where('key', $objectKey)->firstOrFail();

        return ObjectRecord::create([
            'business_object_id' => $object->id,
            'code' => strtoupper($objectKey).'-'.$this->recordSequence,
            'title' => $payload['name'] ?? "{$object->label}{$this->recordSequence}",
            'payload' => $payload,
            'created_by' => $creator?->id,
        ]);
    }

    /** @return array<string, string|null> */
    private function recordTimestamps(): array
    {
        return ObjectRecord::query()
            ->get(['id', 'updated_at'])
            ->mapWithKeys(fn (ObjectRecord $record): array => [
                $record->id => $record->updated_at?->toISOString(),
            ])
            ->all();
    }
}
