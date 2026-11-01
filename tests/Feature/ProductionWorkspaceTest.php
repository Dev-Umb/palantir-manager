<?php

namespace Tests\Feature;

use App\Actions\SaveProductionWorkspace;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProductionWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(XycPrototypeSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'admin')->firstOrFail());

        return $user;
    }

    private function plan(): array
    {
        return ['id' => 'plan-1', 'version' => 1, 'name' => '测试计划', 'type' => '月度计划', 'records' => [['id' => 'line-1', 'material' => '中板', 'spec' => '12mm', 'unit' => '吨', 'quantity' => 35]]];
    }

    private function submit(array $data, int $revision, string $operation = 'save'): TestResponse
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        return $this->postJson('/production-workbench/data/mutate', ['operation' => $operation, 'dataset' => $data, 'expected_revision' => $revision, 'request_id' => (string) Str::uuid(), 'dataset_json' => $json, 'checksum' => hash('sha256', $json)]);
    }

    public function test_save_preserves_adjacent_data_and_rejects_stale_and_invalid_changes(): void
    {
        $this->actingAs($this->admin());
        $original = app(SaveProductionWorkspace::class)->current();
        $data = $original;
        $data['materialDemandPlans'] = [$this->plan()];
        $saved = $this->submit($data, 0)->assertOk()->assertJsonPath('materialDemandPlans.0.records.0.quantity', 35)->json();
        $this->assertSame($original['records'], $saved['records']);
        $this->assertSame($original['progressRecords'], $saved['progressRecords']);
        $this->submit($data, 0)->assertConflict();
        $data = $saved;
        $data['materialDemandPlans'][0]['records'][0]['quantity'] = -1;
        $this->submit($data, $saved['revision'])->assertUnprocessable();
        $data = $saved;
        $data['materialDemandPlans'][0]['records'][] = $data['materialDemandPlans'][0]['records'][0];
        $this->submit($data, $saved['revision'])->assertUnprocessable();
        $this->assertEquals($saved, app(SaveProductionWorkspace::class)->current());
    }

    public function test_restore_is_atomic_and_undo_rejects_intervening_writes(): void
    {
        $this->actingAs($this->admin());
        $before = app(SaveProductionWorkspace::class)->current();
        $data = $before;
        $data['materialDemandPlans'] = [$this->plan()];
        $data['customExtension'] = ['keep' => true];
        $after = $this->submit($data, 0, 'restore')->assertOk()->json();
        $this->assertTrue($after['customExtension']['keep']);
        $undo = $this->submit([], $after['revision'], 'undo')->assertOk()->json();
        $this->assertSame([], $undo['materialDemandPlans']);
        $after = $this->submit($data, $undo['revision'], 'restore')->assertOk()->json();
        $data = $after;
        $data['materialDemandPlans'][0]['name'] = '修改';
        $data['materialDemandPlans'][0]['version']++;
        $saved = $this->submit($data, $after['revision'])->assertOk()->json();
        $this->submit([], $saved['revision'], 'undo')->assertConflict();
        $this->assertTrue($saved['customExtension']['keep']);
    }

    public function test_private_routes_require_both_domains_and_page_has_no_demo_records(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/production-workbench/data/state')->assertForbidden();
        $this->get('/production-workbench/development')->assertForbidden();
        $this->actingAs($this->admin())->get('/production-workbench/development')->assertOk()->assertSee('生产总览')->assertSee('需求计划')->assertDontSee('seed-液压配件-3');
        $this->getJson('/production-workbench/data/state')->assertOk()->assertJsonPath('materialDemandPlans', []);
    }

    public function test_read_only_and_single_domain_permissions_preserve_existing_access(): void
    {
        $admin = $this->admin();
        $role = Role::create(['name' => 'restricted-workbench', 'label' => '只读']);
        $role->permissions()->sync(Permission::whereIn('key', ['material-arrivals.view', 'template-accessories.view'])->pluck('id'));
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $this->actingAs($user)->getJson('/production-workbench/data/state')->assertOk();
        $data = app(SaveProductionWorkspace::class)->current();
        $data['materialDemandPlans'] = [$this->plan()];
        $this->submit($data, 0)->assertForbidden();
        $this->submit($data, 0, 'restore')->assertForbidden();
        $role->permissions()->sync(Permission::where('key', 'material-arrivals.view')->pluck('id'));
        $this->actingAs($user->fresh())->getJson('/production-workbench/data/state')->assertForbidden();
        $this->getJson('/material-arrivals/state')->assertOk();
        $this->actingAs($admin);
        $this->assertSame([], app(SaveProductionWorkspace::class)->current()['materialDemandPlans']);
    }

    public function test_full_dataset_save_retains_empty_strings_extensions_and_legacy_revisions(): void
    {
        $this->actingAs($this->admin());
        $data = app(SaveProductionWorkspace::class)->current();
        $data['records'] = [['id' => 'truck', 'seq' => 1, 'version' => 1, 'shipDate' => '2026-10-01', 'arrivalDate' => '2026-10-01', 'supplier' => '测试', 'plate' => '测试车牌', 'note' => '', 'source' => '', 'items' => [['material' => '中板', 'spec' => '12mm']], 'dispatch' => 30, 'gross' => 40, 'tare' => 10, 'deleted' => false, 'custom' => ['keep' => '']]];
        $before = $this->submit($data, 0, 'restore')->assertOk()->json();
        $data = $before;
        $data['materialDemandPlans'] = [$this->plan()];
        $saved = $this->submit($data, $before['revision'])->assertOk()->json();
        $this->assertSame($before['records'], $saved['records']);
        $this->assertSame('', $saved['records'][0]['note']);
        $this->assertSame(['keep' => ''], $saved['records'][0]['custom']);
        $this->assertSame(1, (int) DB::table('material_arrival_workspaces')->value('revision'));
    }

    public function test_reference_initialization_is_idempotent_and_preserves_saved_plans(): void
    {
        $user = $this->admin();
        $file = tempnam(sys_get_temp_dir(), 'demand-test-');
        try {
            file_put_contents($file, json_encode([$this->plan()]));
            $this->artisan('production-workbench:initialize-demands', ['file' => $file, '--user' => $user->id])->assertSuccessful();
            $before = app(SaveProductionWorkspace::class)->current();
            $this->artisan('production-workbench:initialize-demands', ['file' => $file, '--user' => $user->id])->assertSuccessful();
            $this->assertSame($before, app(SaveProductionWorkspace::class)->current());
            $this->assertCount(1, $before['materialDemandPlans']);
            $legacy = $before;
            unset($legacy['materialDemandPlans']);
            $this->actingAs($user);
            $this->submit($legacy, $before['revision'], 'restore')->assertOk()->assertJsonPath('materialDemandPlans.0.id', 'plan-1');
        } finally {
            unlink($file);
        }
    }
}
