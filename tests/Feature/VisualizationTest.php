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
        $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Dashboard')->has('cockpit')->has('recentProjects'));
    }

    public function test_business_scope_does_not_disclose_other_projects(): void
    {
        $user = $this->user('business');
        $other = User::factory()->create();
        $this->project($user, ['name' => '我的项目', 'occurred_amount' => 100, 'paid_amount' => 0, 'unpaid_amount' => 100]);
        $this->project($other, ['name' => '隐藏项目', 'occurred_amount' => 9000, 'unpaid_amount' => 9000]);
        $this->actingAs($user)->get('/visualization')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('visualization.scope', '我的可见范围')
            ->where('visualization.projects.totals.occurred.value', 100)
            ->where('visualization.projects.totals.paid.value', 0)
            ->has('visualization.projects.top_unpaid', 1)
            ->where('visualization.projects.top_unpaid.0.name', '我的项目'));
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
}
