<?php

namespace Tests\Feature;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VisualizationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $this->seed(XycPrototypeSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());
        ObjectRecord::query()->delete();

        return $user;
    }

    private function project(User $user, array $payload): ObjectRecord
    {
        return ObjectRecord::create([
            'business_object_id' => BusinessObject::where('key', 'project')->firstOrFail()->id,
            'code' => fake()->unique()->bothify('PR-####'),
            'title' => $payload['name'] ?? '项目',
            'created_by' => $user->id,
            'payload' => ['business_owner_user_id' => (string) $user->id, ...$payload],
        ]);
    }

    public function test_real_project_amounts_top_five_and_maintained_date_aging_are_read_only(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 7)->startOfDay());
        $user = $this->user('admin');
        for ($i = 0; $i < 6; $i++) {
            $this->project($user, ['name' => "项目{$i}", 'business_owner_user_id' => $user->id, 'occurred_amount' => 100000, 'paid_amount' => 70000, 'unpaid_amount' => 10000 + $i, 'overall_status' => '合同签署', 'last_payment_date' => $i === 0 ? null : '2026-01-01']);
        }
        $this->project($user, ['name' => '无效日期', 'unpaid_amount' => 999, 'last_payment_date' => '2026-02-30']);
        $this->project($user, ['name' => '未来日期', 'unpaid_amount' => 999, 'last_payment_date' => '2026-12-01']);
        $before = ObjectRecord::all()->toArray();
        $this->actingAs($user)->get('/visualization')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Visualization')->where('visualization.scope', '公司全量')
            ->has('visualization.projects.top_unpaid', 5)
            ->where('visualization.projects.top_unpaid.0.name', '项目5')
            ->where('visualization.projects.top_unpaid.0.unpaid', 10005)
            ->where('visualization.projects.top_unpaid.0.occurred', 100000)
            ->has('visualization.projects.aging', 5)
            ->where('visualization.projects.aging.0.days', 279)
            ->where('visualization.projects.aging_excluded', 3)
            ->where('visualization.projects.active_count', 6)
            ->where('visualization.projects.stages.0.name', '合同签署'));
        $this->assertSame($before, ObjectRecord::all()->toArray());
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Dashboard')->has('cockpit')->has('recentProjects'));
    }

    public function test_annual_output_uses_only_explicit_occurrence_years_and_preserves_all_project_totals(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 10));
        $user = $this->user('admin');
        foreach ([
            ['name' => '2024年发生', 'occurred_amount' => 100.25],
            ['name' => '同年项目', 'remark' => '发生年份：2024年', 'occurred_amount' => 20.75],
            ['name' => '2023年发生江苏', 'occurred_amount' => 0],
            ['name' => '2023年发生调整', 'occurred_amount' => -10],
            ['name' => '2022.2023年发的', 'occurred_amount' => 500],
            ['name' => '2024年发生冲突', 'remark' => '发生年度：2025年', 'occurred_amount' => 600],
            ['name' => '缺年份', 'remark' => '【9月金额更新20261007】', 'first_shipment_date' => '2024-01-01', 'occurred_amount' => 700],
            ['name' => '2025年发生缺金额'],
            ['name' => '26年发生', 'occurred_amount' => 50],
            ['name' => '2030年发生未来', 'occurred_amount' => 800],
        ] as $payload) {
            $this->project($user, $payload);
        }
        $before = ObjectRecord::all()->toArray();
        $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('visualization.projects.annual_output', 3)
            ->where('visualization.projects.annual_output.0.year', 2023)
            ->where('visualization.projects.annual_output.0.amount', -10)
            ->where('visualization.projects.annual_output.0.project_count', 2)
            ->where('visualization.projects.annual_output.1.year', 2024)
            ->where('visualization.projects.annual_output.1.amount', 121)
            ->where('visualization.projects.annual_output.2.year', 2026)
            ->where('visualization.projects.annual_output.2.amount', 50)
            ->where('visualization.projects.annual_output_summary.covered', 5)
            ->where('visualization.projects.annual_output_summary.total', 10)
            ->where('visualization.projects.annual_output_summary.ambiguous_year', 2)
            ->where('visualization.projects.annual_output_summary.unknown_year', 2)
            ->where('visualization.projects.annual_output_summary.missing_amount', 1)
            ->where('visualization.projects.totals.occurred.value', 2761));
        $this->assertSame($before, ObjectRecord::all()->toArray());
    }

    public function test_business_scope_does_not_disclose_other_projects(): void
    {
        $user = $this->user('business');
        $other = User::factory()->create();
        $this->project($user, ['name' => '2024年发生我的项目', 'occurred_amount' => 100, 'paid_amount' => 0, 'unpaid_amount' => 100]);
        $this->project($other, ['name' => '2023年发生隐藏项目', 'occurred_amount' => 9000, 'unpaid_amount' => 9000]);
        $this->actingAs($user)->get('/visualization')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('visualization.scope', '我的可见范围')
            ->where('visualization.projects.totals.occurred.value', 100)
            ->where('visualization.projects.totals.paid.value', 0)
            ->has('visualization.projects.top_unpaid', 1)
            ->where('visualization.projects.top_unpaid.0.name', '2024年发生我的项目')
            ->has('visualization.projects.annual_output', 1)
            ->where('visualization.projects.annual_output.0.year', 2024)
            ->where('visualization.projects.annual_output.0.amount', 100)
            ->where('visualization.projects.annual_output_summary.total', 1));
    }

    public function test_missing_amounts_and_overpayment_do_not_generate_fake_sectors(): void
    {
        $user = $this->user('admin');
        $this->project($user, ['name' => '缺失金额']);
        $this->project($user, ['name' => '超额', 'occurred_amount' => 100, 'paid_amount' => 150]);
        $this->actingAs($user)->get('/visualization')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('visualization.collection.ratio', 150)
            ->where('visualization.collection.chartable', false)
            ->where('visualization.collection.coverage', '1/2')
            ->where('visualization.projects.totals.occurred.value', 100));
    }

    public function test_no_source_permissions_returns_safe_empty_state_and_no_navigation_data(): void
    {
        $user = $this->user('basic');
        $this->actingAs($user)->get('/visualization')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('visualization.collection', null)->where('visualization.projects', null)
            ->where('visualization.details_url', null));
        $this->get('/')->assertOk();
        $user->roles()->detach();
        $this->actingAs($user->fresh())->get('/visualization')->assertForbidden();
    }

    public function test_home_opens_visualization_and_retains_both_existing_dashboard_entries(): void
    {
        $user = $this->user('admin');
        $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Visualization')->where('visualization.dashboard_url', route('dashboard'))
            ->where('nav.0.href', route('dashboard'))->where('nav.1.href', route('home')));
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Dashboard'));
        $this->get('/visualization')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Visualization'));
        $user->roles()->detach();
        $this->actingAs($user->fresh())->get('/')->assertForbidden();
        $this->get('/dashboard')->assertForbidden();
    }

    public function test_map_aggregates_only_visible_projects_without_guessing_factories_or_writing_records(): void
    {
        $user = $this->user('business');
        $other = User::factory()->create();
        $this->project($user, ['contract_status' => '已有加工函', 'unassigned_processing_letter_attachments' => ['letter.pdf'], 'name' => '武汉桥梁工程', 'contract_amount' => 100, 'occurred_amount' => 100, 'paid_amount' => 20, 'factory_subject' => '山西鑫源昌']);
        $this->project($user, ['contract_status' => '已有加工函', 'unassigned_processing_letter_attachments' => ['letter.pdf'], 'name' => '宜昌铁路项目']);
        $this->project($user, ['contract_status' => '已有加工函', 'unassigned_processing_letter_attachments' => ['letter.pdf'], 'name' => '地点未说明', 'customer_address' => '广东省广州市']);
        $this->project($other, ['name' => '上海隐藏项目']);
        $before = ObjectRecord::all()->toArray();
        $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('visualization.map.located_count', 2)
            ->where('visualization.map.unlocated_count', 1)
            ->where('visualization.map.total_count', 3)
            ->has('visualization.map.points', 2)
            ->where('visualization.map.points.0.name', '武汉市')
            ->has('visualization.map.points.0.projects', 1)
            ->where('visualization.map.points.0.projects.0.name', '武汉桥梁工程')
            ->where('visualization.map.points.0.projects.0.factory', 'wenshui')
            ->where('visualization.map.points.1.projects.0.factory', null)
            ->where('visualization.collection.paid', 20));
        $this->assertSame($before, ObjectRecord::all()->toArray());
    }

    public function test_map_explicit_location_overrides_name_and_empty_permissions_do_not_expose_counts(): void
    {
        $user = $this->user('admin');
        $this->project($user, ['contract_status' => '已有加工函', 'unassigned_processing_letter_attachments' => ['letter.pdf'], 'name' => '武汉桥梁工程', 'project_location' => '尼泊尔']);
        $this->get('/')->assertRedirect('/login');
        $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('visualization.map.points.0.name', '尼泊尔')->where('visualization.map.points.0.overseas', true)
            ->where('visualization.map.points.0.projects.0.basis', '项目地点字段'));
        $user->roles()->sync(Role::where('name', 'basic')->firstOrFail()->id);
        $this->actingAs($user->fresh())->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('visualization.map', null));
    }
}
