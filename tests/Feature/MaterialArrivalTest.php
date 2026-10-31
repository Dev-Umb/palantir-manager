<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaterialArrivalTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $actions = ['view', 'manage', 'export', 'recover']): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'arrivals-'.Str::uuid(), 'label' => '测试']);
        foreach ($actions as $action) {
            $permission = Permission::firstOrCreate(['key' => 'material-arrivals.'.$action], ['module' => 'material-arrivals', 'action' => $action, 'label' => $action]);
            $role->permissions()->attach($permission);
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function record(array $overrides = []): array
    {
        return array_replace(['id' => 'sample-1', 'seq' => 1, 'version' => 1, 'shipDate' => '2026-10-30', 'arrivalDate' => '2026-10-31', 'supplier' => '测试单位', 'plate' => '晋A12345', 'dispatch' => 34.8, 'gross' => 51.9, 'tare' => 17, 'note' => '', 'source' => '手工录入', 'items' => [['material' => '槽钢', 'spec' => '10#*9m'], ['material' => '工字钢', 'spec' => '20#*9m']]], $overrides);
    }

    private function input(string $operation = 'save', int $revision = 0, array $extra = []): array
    {
        return ['operation' => $operation, 'expected_revision' => $revision, 'request_id' => (string) Str::uuid(), ...($operation === 'save' ? ['record' => $this->record()] : []), ...$extra];
    }

    private function dataset(array $records = []): array
    {
        return ['records' => $records, 'imports' => [], 'revision' => 0, 'updatedAt' => null];
    }

    public function test_access_navigation_and_empty_database_without_seed_import(): void
    {
        foreach (['', '/workbench', '/state', '/export'] as $path) {
            $this->get('/material-arrivals'.$path)->assertRedirect('/login');
        }
        $this->actingAs($this->user([]))->getJson('/material-arrivals/state')->assertForbidden();
        $this->actingAs($this->user(['view']));
        $this->withoutVite();
        $this->get('/material-arrivals')->assertRedirect(route('production-workbench', ['module' => 'material']));
        $this->getJson('/material-arrivals/state')->assertOk()->assertJsonPath('dataset.records', [])->assertJsonPath('dataset.revision', 0);
        $this->postJson('/material-arrivals/mutate', $this->input())->assertForbidden();
        $this->getJson('/material-arrivals/export')->assertForbidden();
        $html = $this->get('/material-arrivals/workbench')->assertOk()->getContent();
        $this->assertStringContainsString('型材与板材汇总', $html);
        $this->assertStringContainsString('href="'.e(route('dashboard')).'" target="_top"', $html);
        $this->assertStringContainsString('返回 Palantir 面板', $html);
        $this->assertStringNotContainsString('__PLATFORM_HOME__', $html);
        $this->get(route('dashboard'))->assertForbidden();
        $this->assertStringNotContainsString('seed-39', $html);
        $this->assertStringNotContainsString('indexedDB.open', $html);
        $this->assertStringNotContainsString('localStorage.', $html);
    }

    public function test_save_shares_records_but_rejects_duplicate_and_stale_writes(): void
    {
        $this->actingAs($this->user());
        $first = $this->postJson('/material-arrivals/mutate', $this->input())->assertOk()->assertJsonPath('dataset.revision', 1)->json('dataset.records.0');
        $this->actingAs($this->user());
        $this->getJson('/material-arrivals/state')->assertJsonCount(1, 'dataset.records')->assertJsonCount(2, 'dataset.records.0.items');
        $this->postJson('/material-arrivals/mutate', $this->input('save', 0))->assertConflict();
        $this->postJson('/material-arrivals/mutate', $this->input('save', 1, ['record' => $this->record(['id' => 'different-id', 'plate' => ' 晋a12345 '])]))->assertUnprocessable();
        $this->postJson('/material-arrivals/mutate', $this->input('save', 1, ['record' => [...$first, 'note' => '已核对'], 'version' => 1]))->assertOk()->assertJsonPath('dataset.records.0.version', 2);
        $this->postJson('/material-arrivals/mutate', $this->input('save', 2, ['version' => 1]))->assertConflict();
        $this->getJson('/material-arrivals/state')->assertJsonPath('dataset.records.0.note', '已核对');
    }

    public function test_retries_are_idempotent_and_reused_request_ids_cannot_change_content(): void
    {
        $this->actingAs($this->user());
        $input = $this->input();
        $this->postJson('/material-arrivals/mutate', $input)->assertOk();
        $this->postJson('/material-arrivals/mutate', $input)->assertOk()->assertJsonPath('dataset.revision', 1)->assertJsonCount(1, 'dataset.records');
        $input['record']['note'] = 'changed';
        $this->postJson('/material-arrivals/mutate', $input)->assertConflict();
        $this->assertDatabaseCount('material_arrival_requests', 1);
    }

    public function test_validation_zero_weights_unknown_classes_and_invalid_dates(): void
    {
        $this->actingAs($this->user());
        foreach ([['gross' => 1], ['dispatch' => -1], ['dispatch' => '34.8'], ['arrivalDate' => '2026-02-30'], ['shipDate' => '2026-11-01'], ['items' => [['material' => '槽钢', 'spec' => '10#', 'majorClass' => '板材']]], ['items' => [['material' => '其他', 'spec' => 'x']]]] as $change) {
            $this->postJson('/material-arrivals/mutate', $this->input('save', 0, ['record' => $this->record($change)]))->assertUnprocessable();
        }
        $this->postJson('/material-arrivals/mutate', $this->input('save', 0, ['record' => $this->record(['dispatch' => 0, 'gross' => 0, 'tare' => 0])]))->assertOk();
    }

    public function test_delete_restore_checks_record_version_and_active_unique_key(): void
    {
        $this->actingAs($this->user());
        $this->postJson('/material-arrivals/mutate', $this->input())->assertOk();
        $this->postJson('/material-arrivals/mutate', $this->input('delete', 1, ['id' => 'sample-1', 'version' => 1, 'deleted' => true]))->assertOk()->assertJsonPath('dataset.records.0.deleted', true);
        $this->postJson('/material-arrivals/mutate', $this->input('save', 2, ['record' => $this->record(['id' => 'new'])]))->assertOk();
        $this->postJson('/material-arrivals/mutate', $this->input('delete', 3, ['id' => 'sample-1', 'version' => 2, 'deleted' => false]))->assertUnprocessable();
        $this->getJson('/material-arrivals/state')->assertJsonPath('dataset.revision', 3)->assertJsonPath('dataset.records.0.deleted', true);
    }

    public function test_import_is_atomic_and_skips_duplicates(): void
    {
        $this->actingAs($this->user());
        $this->postJson('/material-arrivals/mutate', $this->input())->assertOk();
        $extra = ['hash' => str_repeat('a', 64), 'name' => 'sample.xlsx', 'rows' => [$this->record(['id' => 'new', 'plate' => 'B']), $this->record(['dispatch' => 40])]];
        $this->postJson('/material-arrivals/mutate', $this->input('import', 1, $extra))->assertUnprocessable();
        $this->getJson('/material-arrivals/state')->assertJsonCount(1, 'dataset.records')->assertJsonPath('dataset.imports', []);
        $extra['rows'][1] = $this->record();
        $this->postJson('/material-arrivals/mutate', $this->input('import', 1, $extra))->assertOk()->assertJsonCount(2, 'dataset.records')->assertJsonPath('result.duplicates', 1)->assertJsonCount(1, 'result.add');
        $this->postJson('/material-arrivals/mutate', $this->input('import', 2, $extra))->assertOk()->assertJsonPath('result.sameFile', true)->assertJsonCount(2, 'dataset.records');
    }

    public function test_restore_undo_checksum_scope_and_revision_protection(): void
    {
        $this->actingAs($this->user());
        $this->postJson('/material-arrivals/mutate', $this->input())->assertOk();
        $json = json_encode($this->dataset([$this->record(['id' => 'restored', 'plate' => 'B', 'deleted' => true])]), JSON_UNESCAPED_UNICODE);
        $input = $this->input('restore', 1, ['dataset_json' => $json, 'checksum' => str_repeat('0', 64)]);
        $this->postJson('/material-arrivals/mutate', $input)->assertUnprocessable();
        $input['checksum'] = hash('sha256', $json);
        $this->postJson('/material-arrivals/mutate', $input)->assertOk()->assertJsonPath('dataset.records.0.id', 'restored');
        $this->postJson('/material-arrivals/mutate', $this->input('undo', 1))->assertConflict();
        $this->postJson('/material-arrivals/mutate', $this->input('undo', 2))->assertOk()->assertJsonPath('dataset.records.0.id', 'sample-1');
        $this->postJson('/material-arrivals/mutate', $this->input('undo', 3))->assertConflict();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_recovery_requires_separate_permission_and_cannot_be_smuggled_into_save(): void
    {
        $this->actingAs($this->user(['view', 'manage']));
        $this->postJson('/material-arrivals/mutate', $this->input('restore'))->assertForbidden();
        $this->postJson('/material-arrivals/mutate', $this->input('undo'))->assertForbidden();
        $this->postJson('/material-arrivals/mutate', $this->input('save', 0, ['dataset_json' => json_encode($this->dataset())]))->assertOk()->assertJsonCount(1, 'dataset.records');
    }

    public function test_personal_state_is_database_persistent_isolated_and_versioned(): void
    {
        $first = $this->user();
        $second = $this->user();
        $this->actingAs($first);
        $input = ['key' => 'draft', 'value' => '{"record":{"plate":"DRAFT"}}', 'expected_revision' => 0];
        $this->putJson('/material-arrivals/preferences', $input)->assertOk()->assertJsonPath('revision', 1);
        $this->actingAs($second)->getJson('/material-arrivals/state')->assertJsonPath('preferences', []);
        $this->actingAs($first)->getJson('/material-arrivals/state')->assertJsonPath('preferences.draft.value', $input['value'])->assertJsonPath('dataset.records', []);
        $this->putJson('/material-arrivals/preferences', $input)->assertOk()->assertJsonPath('revision', 1);
        $this->putJson('/material-arrivals/preferences', [...$input, 'value' => '{}'])->assertConflict();
        $this->putJson('/material-arrivals/preferences', [...$input, 'expected_revision' => 1, 'value' => null])->assertOk()->assertJsonPath('revision', 2);
        $this->getJson('/material-arrivals/export')->assertOk()->assertJsonMissingPath('preferences');
    }

    public function test_permission_install_is_additive_and_restricted_accounts_stay_restricted(): void
    {
        $admin = Role::create(['name' => 'admin', 'label' => '管理']);
        $production = Role::create(['name' => 'production', 'label' => '生产']);
        $this->artisan('material-arrivals:install-permissions')->assertSuccessful();
        $this->artisan('material-arrivals:install-permissions')->assertSuccessful();
        $this->assertSame(4, $admin->permissions()->count());
        $this->assertSame(0, $production->permissions()->count());
        $user = $this->user();
        $restricted = Role::create(['name' => 'timebook_operator', 'label' => '专用']);
        $user->roles()->attach($restricted);
        $this->actingAs($user)->getJson('/material-arrivals/state')->assertForbidden();
        $this->assertFalse($user->fresh()->canDo('material-arrivals.view'));
    }
}
