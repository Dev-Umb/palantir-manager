<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TemplateAccessoryTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $actions = ['view', 'manage', 'export', 'recover']): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'templates-'.Str::uuid(), 'label' => '测试']);
        foreach ($actions as $action) {
            $permission = Permission::firstOrCreate(['key' => 'template-accessories.'.$action], ['module' => 'template-accessories', 'action' => $action, 'label' => $action]);
            $role->permissions()->attach($permission);
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function row(array $extra = []): array
    {
        return array_replace(['id' => 'test-one', 'kind' => '台车模板', 'seq' => 1, 'version' => 1, 'project' => '测试标段', 'subproject' => '测试隧道', 'type' => '二衬台车', 'estimated' => 110, 'shipped' => null, 'intelligent' => null, 'quantity' => 1, 'progress' => '已下放', 'shipping' => '未发', 'orderDate' => '2026-10-03', 'shipDate' => '', 'note' => '', 'deleted' => false], $extra);
    }

    private function mutation(string $operation, int $revision, array $extra = []): array
    {
        return ['operation' => $operation, 'expected_revision' => $revision, 'request_id' => (string) Str::uuid(), ...$extra];
    }

    private function save(int $revision, array $rows): TestResponse
    {
        return $this->postJson('/template-accessories/mutate', $this->mutation('save', $revision, ['entries' => $rows]));
    }

    public function test_access_empty_state_and_independent_permissions(): void
    {
        $this->get('/template-accessories')->assertRedirect('/login');
        $this->actingAs($this->user(['view']));
        $this->getJson('/template-accessories/state')->assertOk()->assertJsonPath('dataset.progressRecords', []);
        $this->get('/template-accessories/workbench')->assertOk()->assertSee('返回 Palantir 面板')->assertSee('台车模板')->assertDontSee('seed-液压配件');
        $this->save(0, [['record' => $this->row()]])->assertForbidden();
        $this->getJson('/template-accessories/export')->assertForbidden();
        $this->getJson('/material-arrivals/state')->assertForbidden();
        $this->putJson('/template-accessories/preferences', ['key' => 'inline', 'value' => '[]', 'expected_revision' => 0])->assertForbidden();
    }

    public function test_atomic_save_null_zero_validation_retry_and_conflict(): void
    {
        $this->actingAs($this->user());
        $input = $this->mutation('save', 0, ['entries' => [['record' => $this->row()]]]);
        $this->postJson('/template-accessories/mutate', $input)->assertOk()->assertJsonPath('dataset.progressRecords.0.shipped', null);
        $this->postJson('/template-accessories/mutate', $input)->assertOk()->assertJsonPath('dataset.revision', 1);
        $this->save(1, [['record' => $this->row(['shipped' => 0]), 'version' => 1], ['record' => $this->row(['id' => 'bad', 'estimated' => -1])]])->assertUnprocessable();
        $this->getJson('/template-accessories/state')->assertJsonPath('dataset.progressRecords.0.shipped', null)->assertJsonPath('dataset.revision', 1);
        $this->save(1, [['record' => $this->row(['shipped' => 0]), 'version' => 1]])->assertOk()->assertJsonPath('dataset.progressRecords.0.shipped', 0);
        $this->save(1, [['record' => $this->row(), 'version' => 1]])->assertConflict();
        $this->save(2, [['record' => $this->row(['shipping' => '已发']), 'version' => 2]])->assertUnprocessable();
        $this->save(2, [['record' => $this->row(['orderDate' => '2026-02-30']), 'version' => 2]])->assertUnprocessable();
    }

    public function test_import_preserves_unrelated_rows_and_requires_unique_matching(): void
    {
        $this->actingAs($this->user());
        $this->save(0, [['record' => $this->row()], ['record' => $this->row(['id' => 'other', 'project' => '其他项目'])]])->assertOk();
        $import = fn (int $rev, array $rows, bool $replace, string $hash) => $this->mutation('import', $rev, ['rows' => $rows, 'kind' => '台车模板', 'replace' => $replace, 'hash' => $hash, 'name' => '测试.xlsx']);
        $changed = $this->row(['id' => 'parsed', 'shipped' => 0]);
        $this->postJson('/template-accessories/mutate', $import(1, [$changed], false, str_repeat('a', 64)))->assertOk()->assertJsonCount(2, 'dataset.progressRecords')->assertJsonPath('dataset.progressRecords.0.shipped', null);
        $this->postJson('/template-accessories/mutate', $import(2, [$changed], true, str_repeat('a', 64)))->assertOk()->assertJsonPath('dataset.progressRecords.0.shipped', 0);
        $this->postJson('/template-accessories/mutate', $import(3, [$changed], true, str_repeat('a', 64)))->assertOk()->assertJsonPath('result.sameFile', true);
        $this->save(4, [['record' => $this->row(['id' => 'duplicate'])]])->assertOk();
        $this->postJson('/template-accessories/mutate', $import(5, [$this->row(['shipped' => 5])], true, str_repeat('b', 64)))->assertUnprocessable();
        $this->getJson('/template-accessories/state')->assertJsonPath('dataset.revision', 5);
    }

    public function test_private_state_is_user_scoped_and_revision_guarded(): void
    {
        $one = $this->user();
        $two = $this->user();
        $this->actingAs($one)->putJson('/template-accessories/preferences', ['key' => 'draft', 'value' => '{"project":"mine"}', 'expected_revision' => 0, 'user_id' => $two->id])->assertOk();
        $this->actingAs($two)->getJson('/template-accessories/state')->assertJsonPath('preferences', []);
        $this->actingAs($one)->putJson('/template-accessories/preferences', ['key' => 'draft', 'value' => '{}', 'expected_revision' => 0])->assertConflict();
        $this->getJson('/template-accessories/export')->assertJsonMissingPath('preferences');
    }

    public function test_trash_restore_undo_are_isolated_from_material_arrivals(): void
    {
        $this->actingAs($this->user());
        $originalMaterial = DB::table('material_arrival_workspaces')->value('dataset');
        $this->save(0, [['record' => $this->row()]])->assertOk();
        $backup = $this->getJson('/template-accessories/export')->json();
        $this->postJson('/template-accessories/mutate', $this->mutation('delete', 1, ['id' => 'test-one', 'version' => 1, 'deleted' => true]))->assertOk();
        $encoded = json_encode($backup);
        $this->postJson('/template-accessories/mutate', $this->mutation('restore', 2, ['dataset_json' => $encoded, 'checksum' => hash('sha256', $encoded)]))->assertOk()->assertJsonPath('dataset.progressRecords.0.deleted', false);
        $this->postJson('/template-accessories/mutate', $this->mutation('undo', 3))->assertOk()->assertJsonPath('dataset.progressRecords.0.deleted', true);
        $this->assertSame($originalMaterial, DB::table('material_arrival_workspaces')->value('dataset'));
    }
}
