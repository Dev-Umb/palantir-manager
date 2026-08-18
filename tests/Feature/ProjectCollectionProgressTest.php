<?php

namespace Tests\Feature;

use App\Actions\SyncProjectFinance;
use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\CollectionProgress;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectCollectionProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_progress_uses_only_occurred_amount_and_is_not_capped(): void
    {
        $finance = app(SyncProjectFinance::class);

        $normal = $finance->normalizePayload([
            'contract_amount' => 1000,
            'occurred_amount' => 200,
            'paid_amount' => 50,
            'invoiced_amount' => 25,
        ]);
        $withoutOccurredAmount = $finance->normalizePayload([
            'contract_amount' => 1000,
            'occurred_amount' => 0,
            'paid_amount' => 50,
            'invoiced_amount' => 25,
        ]);
        $overCollected = $finance->normalizePayload([
            'contract_amount' => 1000,
            'occurred_amount' => 100,
            'paid_amount' => 120,
        ]);

        $this->assertSame(25.0, $normal['payment_progress']);
        $this->assertNull($withoutOccurredAmount['payment_progress']);
        $this->assertSame(950.0, $withoutOccurredAmount['unpaid_amount']);
        $this->assertSame(975.0, $withoutOccurredAmount['uninvoiced_amount']);
        $this->assertSame(120.0, $overCollected['payment_progress']);
    }

    public function test_invalid_or_non_positive_occurred_amount_is_unavailable(): void
    {
        $progress = app(CollectionProgress::class);

        $this->assertNull($progress->percentage(null, 20));
        $this->assertNull($progress->percentage('not-a-number', 20));
        $this->assertNull($progress->percentage(INF, 20));
        $this->assertNull($progress->percentage(0, 20));
        $this->assertNull($progress->percentage(-10, 20));
    }

    public function test_total_ratio_excludes_invalid_denominators_and_can_exceed_one_hundred_percent(): void
    {
        $progress = app(CollectionProgress::class);
        $summary = $progress->summarize(collect([
            new ObjectRecord(['payload' => ['occurred_amount' => 100, 'paid_amount' => 125]]),
            new ObjectRecord(['payload' => ['occurred_amount' => 0, 'paid_amount' => 20]]),
            new ObjectRecord(['payload' => ['occurred_amount' => 'invalid', 'paid_amount' => 20]]),
        ]));

        $this->assertSame(125.0, $summary['ratio']);
        $this->assertSame(125.0, $summary['paid_amount']);
        $this->assertSame(100.0, $summary['occurred_amount']);
        $this->assertSame(1, $summary['covered_records']);
        $this->assertSame(3, $summary['total_records']);

        $unavailable = $progress->summarize(collect([
            new ObjectRecord(['payload' => ['occurred_amount' => 0, 'paid_amount' => 20]]),
        ]));
        $this->assertNull($unavailable['ratio']);
        $this->assertSame(0.0, $unavailable['occurred_amount']);
    }

    public function test_recalculation_is_preview_first_and_idempotent(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $projectObject = BusinessObject::where('key', 'project')->firstOrFail();
        $projectObject->records()->delete();
        $customerId = ObjectRecord::whereRelation('businessObject', 'key', 'customer')->value('id');
        $project = ObjectRecord::create([
            'business_object_id' => $projectObject->id,
            'code' => 'XYC-RECALCULATE-001',
            'title' => '重算项目',
            'payload' => [
                'name' => '重算项目',
                'project_no' => 'XYC-RECALCULATE-001',
                'customer_id' => $customerId,
                'contract_amount' => 500,
                'occurred_amount' => 400,
                'paid_amount' => 100,
                'unpaid_amount' => 400,
            ],
        ]);
        $project->update(['payload' => [
            ...($project->payload ?? []),
            'payment_progress' => 99,
        ]]);
        $projectWithoutLedger = ObjectRecord::create([
            'business_object_id' => $projectObject->id,
            'code' => 'XYC-RECALCULATE-002',
            'title' => '无台账重算项目',
            'payload' => [
                'name' => '无台账重算项目',
                'project_no' => 'XYC-RECALCULATE-002',
                'customer_id' => $customerId,
                'contract_amount' => 900,
                'occurred_amount' => 200,
                'paid_amount' => 100,
                'unpaid_amount' => 800,
                'payment_progress' => 10,
            ],
        ]);
        $projectWithoutDenominator = ObjectRecord::create([
            'business_object_id' => $projectObject->id,
            'code' => 'XYC-RECALCULATE-003',
            'title' => '无发生额重算项目',
            'payload' => [
                'name' => '无发生额重算项目',
                'project_no' => 'XYC-RECALCULATE-003',
                'customer_id' => $customerId,
                'contract_amount' => 900,
                'occurred_amount' => 0,
                'paid_amount' => 100,
                'unpaid_amount' => 800,
                'payment_progress' => 0,
            ],
        ]);

        $projectPayload = $project->fresh()->payload;
        $projectWithoutLedgerPayload = $projectWithoutLedger->fresh()->payload;
        $projectWithoutDenominatorPayload = $projectWithoutDenominator->fresh()->payload;
        $projectUpdatedAt = $project->fresh()->updated_at->toJSON();
        $auditCount = AuditLog::count();

        $this->artisan('xyc:recalculate-project-collection-progress')
            ->expectsOutputToContain('预览完成：扫描 3，预计更新 3，无需变化 0，无法计算 1')
            ->assertSuccessful();

        $this->assertSame(99.0, (float) $project->fresh()->payload['payment_progress']);
        $this->assertSame($projectUpdatedAt, $project->fresh()->updated_at->toJSON());
        $this->assertSame($auditCount, AuditLog::count());

        $this->artisan('xyc:recalculate-project-collection-progress', ['--execute' => true])
            ->expectsOutputToContain('执行完成：扫描 3，已更新 3，无需变化 0，无法计算 1')
            ->assertSuccessful();

        $this->assertSame(25.0, (float) $project->fresh()->payload['payment_progress']);
        $this->assertSame(50.0, (float) $projectWithoutLedger->fresh()->payload['payment_progress']);
        $this->assertNull($projectWithoutDenominator->fresh()->payload['payment_progress']);
        $this->assertSame(
            collect($projectPayload)->except('payment_progress')->all(),
            collect($project->fresh()->payload)->except('payment_progress')->all(),
        );
        $this->assertSame(
            collect($projectWithoutLedgerPayload)->except('payment_progress')->all(),
            collect($projectWithoutLedger->fresh()->payload)->except('payment_progress')->all(),
        );
        $this->assertSame(
            collect($projectWithoutDenominatorPayload)->except('payment_progress')->all(),
            collect($projectWithoutDenominator->fresh()->payload)->except('payment_progress')->all(),
        );
        $this->assertSame($auditCount + 3, AuditLog::count());

        $this->artisan('xyc:recalculate-project-collection-progress', ['--execute' => true])
            ->expectsOutputToContain('执行完成：扫描 3，已更新 0，无需变化 3，无法计算 1')
            ->assertSuccessful();

        $this->assertSame($auditCount + 3, AuditLog::count());
    }

    public function test_dashboard_uses_total_paid_divided_by_total_occurred(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->artisan('xyc:admin', [
            'email' => 'ratio-admin@example.com',
            '--password' => 'password123',
        ])->assertSuccessful();

        $projectObject = BusinessObject::where('key', 'project')->firstOrFail();
        $projectObject->records()->delete();
        $customerId = ObjectRecord::whereRelation('businessObject', 'key', 'customer')->value('id');
        ObjectRecord::create([
            'business_object_id' => $projectObject->id,
            'code' => 'XYC-RATIO-001',
            'title' => '比例项目一',
            'payload' => [
                'name' => '比例项目一',
                'project_no' => 'XYC-RATIO-001',
                'customer_id' => $customerId,
                'occurred_amount' => 100,
                'paid_amount' => 20,
            ],
        ]);

        $secondProject = ObjectRecord::create([
            'business_object_id' => $projectObject->id,
            'code' => 'XYC-RATIO-002',
            'title' => '比例项目二',
            'payload' => [
                'name' => '比例项目二',
                'project_no' => 'XYC-RATIO-002',
                'customer_id' => $customerId,
            ],
        ]);
        $secondProject->update(['payload' => [
            ...$secondProject->payload,
            'occurred_amount' => 300,
            'paid_amount' => 180,
        ]]);

        $this->actingAs(User::where('email', 'ratio-admin@example.com')->firstOrFail())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cockpit.kpis.1.key', 'collection_rate')
                ->where('cockpit.kpis.1.label', '总回款比例')
                ->where('cockpit.kpis.1.value', 50)
                ->where('cockpit.kpis.1.paid_amount', 200)
                ->where('cockpit.kpis.1.occurred_amount', 400)
                ->where('cockpit.kpis.1.coverage.valid', 2)
                ->where('cockpit.kpis.1.coverage.total', 2));
    }

    public function test_dashboard_ratio_uses_the_salespersons_visible_project_scope(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $scopedFinanceRole = Role::create(['name' => 'scoped_finance', 'label' => '范围财务查看']);
        $scopedFinanceRole->permissions()->sync(Permission::where('key', 'dashboard.view')->pluck('id'));
        $roleIds = [Role::where('name', 'business')->firstOrFail()->id, $scopedFinanceRole->id];
        $salespersonA = User::factory()->create(['name' => '业务员甲']);
        $salespersonB = User::factory()->create(['name' => '业务员乙']);
        $salespersonA->roles()->sync($roleIds);
        $salespersonB->roles()->sync($roleIds);

        $customerId = ObjectRecord::whereRelation('businessObject', 'key', 'customer')->value('id');
        $projectObject = BusinessObject::where('key', 'project')->firstOrFail();

        foreach ([
            [$salespersonA, 'A', 100, 25],
            [$salespersonB, 'B', 900, 900],
        ] as [$salesperson, $suffix, $occurredAmount, $paidAmount]) {
            $project = ObjectRecord::create([
                'business_object_id' => $projectObject->id,
                'code' => "XYC-SCOPE-{$suffix}",
                'title' => "范围项目{$suffix}",
                'created_by' => $salesperson->id,
                'payload' => [
                    'name' => "范围项目{$suffix}",
                    'project_no' => "XYC-SCOPE-{$suffix}",
                    'customer_id' => $customerId,
                    'business_owner_user_id' => (string) $salesperson->id,
                    'occurred_amount' => $occurredAmount,
                    'paid_amount' => $paidAmount,
                ],
            ]);
        }

        $this->actingAs($salespersonA)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cockpit.kpis.1.key', 'collection_rate')
                ->where('cockpit.kpis.1.value', 25)
                ->where('cockpit.kpis.1.paid_amount', 25)
                ->where('cockpit.kpis.1.occurred_amount', 100)
                ->where('cockpit.kpis.1.coverage.valid', 1)
                ->where('cockpit.kpis.1.coverage.total', 1));
    }
}
