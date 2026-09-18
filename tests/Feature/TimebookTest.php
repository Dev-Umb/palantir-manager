<?php

namespace Tests\Feature;

use App\Actions\SyncXycMetadata;
use App\Ai\AiHistoryAuthorization;
use App\Ai\AiToolEventProjector;
use App\Ai\Tools\QueryTimebookTool;
use App\Ai\XycDataAccess;
use App\Ai\XycDataAgent;
use App\Models\AiRun;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TimebookEntry;
use App\Models\TimebookEntryAudit;
use App\Models\TimebookWorker;
use App\Models\User;
use App\Support\TimebookName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;
use ZipArchive;

class TimebookTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $actions = ['view', 'create', 'update', 'delete', 'export', 'audit', 'ai.query']): User
    {
        $this->artisan('timebook:install-permissions')->assertSuccessful();
        $role = Role::create(['name' => 'timebook-test-'.Role::count(), 'label' => '记工测试']);
        $role->permissions()->attach(Permission::whereIn('key', array_map(fn ($action) => 'timebook.'.$action, $actions))->pluck('id'));
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    private function entry(array $overrides = []): array
    {
        return [...['name' => '张三', 'day' => '2026-09-18', 'days' => 1, 'overtime' => 0, 'project' => '焊接', 'note' => ''], ...$overrides];
    }

    public function test_permissions_gate_every_read_write_export_and_history_entry_point(): void
    {
        $entry = TimebookEntry::factory()->create();
        foreach (['/timebook', '/timebook/records', '/timebook/names', '/timebook/export.xlsx', "/timebook/entries/{$entry->id}/history"] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->actingAs($this->user([]));
        foreach (['/timebook', '/timebook/records', '/timebook/names', '/timebook/export.xlsx', "/timebook/entries/{$entry->id}/history"] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->postJson('/timebook/entries', $this->entry())->assertForbidden();
        $this->actingAs($this->user(['view']));
        $this->getJson('/timebook/records')->assertOk()->assertJsonPath('totals.count', 1);
        $this->getJson('/timebook/names')->assertOk();
        $this->postJson('/timebook/entries', $this->entry())->assertForbidden();
        $this->putJson("/timebook/entries/{$entry->id}", $this->entry(['version' => 1]))->assertForbidden();
        $this->deleteJson("/timebook/entries/{$entry->id}", ['version' => 1])->assertForbidden();
        $this->postJson("/timebook/entries/{$entry->id}/restore", ['version' => 1])->assertForbidden();
        $this->get('/timebook/export.xlsx')->assertForbidden();
        $this->get("/timebook/entries/{$entry->id}/history")->assertForbidden();
    }

    public function test_names_are_free_normalized_and_suggestions_keep_historical_totals_and_literal_search(): void
    {
        $this->actingAs($this->user());
        $first = $this->postJson('/timebook/entries', $this->entry(['name' => ' Ａlice　 Smith ']))->assertCreated()->json();
        $this->postJson('/timebook/entries', $this->entry(['name' => 'alice smith', 'day' => '2026-09-19', 'days' => 0.5, 'overtime' => 0.1]))->assertCreated()->assertJsonPath('worker_id', $first['worker_id']);
        $this->assertDatabaseCount('timebook_workers', 1);
        $this->assertSame(TimebookName::key('STRASSE'), TimebookName::key('Straße'));
        $this->getJson('/timebook/names?q=ALICE')->assertOk()->assertJsonPath('0.name', 'Alice Smith')->assertJsonPath('0.days', 1.5);
        $this->getJson('/timebook/records?q=%25')->assertOk()->assertJsonPath('totals.count', 0);
        $this->postJson('/timebook/entries', $this->entry(['name' => '50%_组']))->assertCreated();
        $this->getJson('/timebook/records?q=%25_')->assertOk()->assertJsonPath('totals.count', 1);
    }

    public function test_duplicate_stale_edit_delete_restore_and_full_audits_are_atomic(): void
    {
        $actor = $this->user();
        $this->actingAs($actor);
        $row = $this->postJson('/timebook/entries', $this->entry())->assertCreated()->json();
        $id = $row['id'];
        $this->postJson('/timebook/entries', $this->entry())->assertConflict()->assertJsonPath('existing.id', $id);
        $this->putJson("/timebook/entries/$id", $this->entry(['days' => 0.5, 'overtime' => 2, 'version' => 1]))->assertOk()->assertJsonPath('version', 2);
        $this->putJson("/timebook/entries/$id", $this->entry(['name' => '不应创建', 'version' => 1]))->assertConflict();
        $this->assertDatabaseCount('timebook_workers', 1);
        $this->deleteJson("/timebook/entries/$id", ['version' => 1])->assertConflict();
        $this->deleteJson("/timebook/entries/$id", ['version' => 2])->assertOk()->assertJsonPath('version', 3);
        $this->getJson('/timebook/records')->assertJsonPath('totals.count', 0);
        $this->getJson('/timebook/names?q=张')->assertJsonPath('0.days', 0);
        $this->postJson("/timebook/entries/$id/restore", ['version' => 3])->assertOk()->assertJsonPath('version', 4);
        $this->getJson("/timebook/entries/$id/history")->assertOk()->assertJsonCount(4)->assertJsonPath('0.action', '恢复')
            ->assertJsonPath('0.actor_name', $actor->name)->assertJsonPath('0.before.deleted', 1)->assertJsonPath('0.after.deleted', 0);
        $this->assertDatabaseCount('timebook_entries', 1);
        $this->assertSame(1, TimebookEntry::findOrFail($id)->half_days);
    }

    public function test_rename_affects_one_entry_and_restore_never_overwrites_replacement(): void
    {
        $this->actingAs($this->user());
        $first = $this->postJson('/timebook/entries', $this->entry())->assertCreated()->json('id');
        $second = $this->postJson('/timebook/entries', $this->entry(['day' => '2026-09-19']))->assertCreated()->json('id');
        $this->putJson("/timebook/entries/$second", $this->entry(['name' => '李四', 'day' => '2026-09-19', 'version' => 1]))->assertOk();
        $this->assertSame('张三', TimebookEntry::findOrFail($first)->worker->name);
        $this->deleteJson("/timebook/entries/$first", ['version' => 1])->assertOk();
        $replacement = $this->postJson('/timebook/entries', $this->entry(['overtime' => 3]))->assertCreated()->json('id');
        $this->postJson("/timebook/entries/$first/restore", ['version' => 2])->assertConflict();
        $this->assertSame(1, TimebookEntry::findOrFail($first)->deleted);
        $this->assertSame(180, TimebookEntry::findOrFail($replacement)->overtime_minutes);
        $this->assertSame(2, TimebookEntryAudit::where('entry_id', $first)->count());
    }

    public function test_validation_rejects_invalid_dates_booleans_fractional_minutes_and_zero_without_creating_workers(): void
    {
        $this->actingAs($this->user());
        foreach ([['name' => '　 '], ['day' => '2026-02-30'], ['days' => 2], ['days' => true], ['overtime' => true],
            ['overtime' => -1], ['overtime' => 25], ['overtime' => 0.001], ['days' => 0, 'overtime' => 0], ['name' => str_repeat('张', 41)]] as $invalid) {
            $this->postJson('/timebook/entries', $this->entry($invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('timebook_workers', 0);
        $id = $this->postJson('/timebook/entries', $this->entry(['days' => 0, 'overtime' => 0.1]))->assertCreated()->assertJsonPath('overtime_minutes', 6)->json('id');
        $this->putJson("/timebook/entries/$id", $this->entry(['version' => true]))->assertUnprocessable();
        $this->getJson('/timebook/records?start=2026-10-02&end=2026-09-01')->assertUnprocessable();
    }

    public function test_aggregates_cover_all_pages_and_filters_are_inclusive_with_overtime_only_people(): void
    {
        $this->actingAs($this->user());
        $worker = TimebookWorker::factory()->create(['name' => '张三', 'name_key' => '张三']);
        foreach (range(1, 20) as $day) {
            TimebookEntry::factory()->create(['worker_id' => $worker->id, 'day' => sprintf('2026-09-%02d', $day), 'half_days' => 1, 'overtime_minutes' => 6]);
        }
        TimebookEntry::factory()->create(['half_days' => 0, 'overtime_minutes' => 60]);
        TimebookEntry::factory()->create(['deleted' => 1, 'half_days' => 2, 'overtime_minutes' => 600]);
        $this->getJson('/timebook/records?start=2026-09-01&end=2026-09-30')->assertOk()->assertJsonCount(12, 'records')
            ->assertJsonPath('totals.count', 21)->assertJsonPath('totals.days', 10)->assertJsonPath('totals.overtime', 3)->assertJsonPath('totals.people', 2);
        $this->getJson('/timebook/records?worker='.$worker->id.'&start=2026-09-02&end=2026-09-02')->assertJsonPath('totals.count', 1)->assertJsonPath('totals.days', 0.5);
        $this->getJson('/timebook/records?page=2')->assertJsonCount(9, 'records')->assertJsonPath('totals.count', 21);
        $this->getJson('/timebook/records?end=2026-09-01')->assertJsonPath('totals.count', 1);
    }

    public function test_xlsx_contains_three_sheets_all_filtered_records_dates_numbers_and_literal_text(): void
    {
        $this->actingAs($this->user());
        $worker = TimebookWorker::factory()->create(['name' => '=1+1', 'name_key' => '=1+1']);
        foreach (range(1, 15) as $day) {
            TimebookEntry::factory()->create(['worker_id' => $worker->id, 'day' => sprintf('2026-09-%02d', $day), 'note' => '=HYPERLINK("https://example.invalid")', 'half_days' => 1, 'overtime_minutes' => 6]);
        }
        TimebookEntry::factory()->create(['day' => '2026-10-01']);
        $response = $this->get('/timebook/export.xlsx?start=2026-09-01&end=2026-09-30')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'timebook-test-');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $this->assertStringContainsString('人员汇总', $zip->getFromName('xl/workbook.xml'));
            $this->assertStringContainsString('导出说明', $zip->getFromName('xl/workbook.xml'));
            $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet2.xml'));
            $this->assertCount(16, $xml->sheetData->row);
            $this->assertSame('1', (string) $xml->sheetData->row[1]->c[0]['s']);
            $this->assertSame('inlineStr', (string) $xml->sheetData->row[1]->c[1]['t']);
            $this->assertSame('=1+1', (string) $xml->sheetData->row[1]->c[1]->is->t);
            $this->assertStringNotContainsString('<f>', $zip->getFromName('xl/worksheets/sheet2.xml'));
            $this->assertStringContainsString('<v>7.5</v>', $zip->getFromName('xl/worksheets/sheet1.xml'));
        } finally {
            $zip->close();
            unlink($path);
        }
        $this->get('/timebook/export.xlsx?q=没有')->assertOk();
    }

    public function test_ai_reads_same_full_totals_and_requires_fresh_separate_grants(): void
    {
        $user = $this->user();
        $tool = new QueryTimebookTool($user);
        $this->assertFalse(json_decode($tool->handle(new Request([])), true)['ok']);
        $permission = Permission::firstOrCreate(['key' => 'ai.harness.view'], ['module' => 'ai', 'action' => 'view', 'label' => 'AI']);
        $role = $user->roles()->first();
        $role->permissions()->attach($permission);
        TimebookEntry::factory()->count(15)->create();
        $result = json_decode($tool->handle(new Request(['limit' => 1])), true);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['detail_is_partial']);
        $this->assertCount(1, $result['records']);
        $this->assertSame(15, $result['totals']['count']);
        $this->assertSame(15, TimebookEntry::count());
        $this->assertSame(0, TimebookEntryAudit::count());
        $this->assertFalse(json_decode($tool->handle(new Request(['start' => 'bad'])), true)['ok']);
        $this->assertContains('query_timebook', collect(app(XycDataAgent::class, ['user' => $user->fresh()])->tools())->map->name()->all());
        $role->permissions()->detach(Permission::where('key', 'timebook.ai.query')->first()->id);
        $denied = json_decode($tool->handle(new Request([])), true);
        $this->assertFalse($denied['ok']);
        $this->assertArrayNotHasKey('records', $denied);
        $this->assertNotContains('query_timebook', collect(app(XycDataAgent::class, ['user' => $user->fresh()])->tools())->map->name()->all());
        $this->assertDatabaseCount('object_records', 0);
    }

    public function test_permission_install_is_idempotent_and_does_not_reset_other_grants_or_objects(): void
    {
        app(SyncXycMetadata::class)->handle();
        $user = $this->user(['view']);
        $role = $user->roles()->first();
        $extra = Permission::create(['key' => 'custom.retained', 'module' => 'custom', 'action' => 'view', 'label' => '保留']);
        $role->permissions()->attach($extra);
        $before = DB::table('permission_role')->orderBy('role_id')->orderBy('permission_id')->get()->toArray();
        $objects = BusinessObject::all()->toArray();
        $this->artisan('timebook:install-permissions')->assertSuccessful();
        $this->artisan('timebook:install-permissions')->assertSuccessful();
        $this->assertEquals($before, DB::table('permission_role')->orderBy('role_id')->orderBy('permission_id')->get()->toArray());
        $this->assertSame($objects, BusinessObject::all()->toArray());
        $this->assertSame(7, Permission::where('module', 'timebook')->count());
        $this->assertSame(0, ObjectRecord::count());
        app(SyncXycMetadata::class)->handle();
        $this->assertTrue($user->fresh()->canDo('timebook.view'));
        $business = Role::where('name', 'business')->firstOrFail();
        $this->assertFalse($business->permissions()->where('module', 'timebook')->exists());
    }

    public function test_ai_result_provenance_and_history_follow_module_permission_revocation(): void
    {
        config(['ai.harness_v2' => true]);
        Queue::fake();
        $user = $this->user();
        $role = $user->roles()->first();
        $role->permissions()->attach(Permission::firstOrCreate(['key' => 'ai.harness.view'], ['module' => 'ai', 'action' => 'view', 'label' => 'AI']));
        $this->actingAs($user->fresh())->postJson('/ai/runs', [
            'message' => '查询工日簿', 'client_request_id' => (string) str()->uuid(),
        ])->assertSuccessful();
        $run = AiRun::where('user_id', $user->id)->sole();
        $payload = (new QueryTimebookTool($user->fresh()))->handle(new Request(['limit' => null, 'page' => null]));
        app(AiToolEventProjector::class)->completed($run, new ToolResult('timebook-call', 'query_timebook', [], $payload));
        $run->refresh();
        $this->assertSame('timebook', $run->sources[0]['object_key']);
        $this->assertSame('timebook', $run->provenance[0]['object_key']);
        $this->assertNotEmpty($run->provenance[0]['query_hash']);
        $this->getJson('/ai/runs/'.$run->id)->assertOk();
        $authorization = app(AiHistoryAuthorization::class);
        $this->assertTrue($authorization->allowsRun($user->fresh(), $run));
        $mixed = clone $run;
        $mixed->provenance = [...$run->provenance, ['object_key' => 'project', 'record_ids' => []]];
        $this->assertFalse($authorization->allowsRun($user->fresh(), $mixed));
        $role->permissions()->detach(Permission::where('key', 'timebook.ai.query')->sole()->id);
        $this->actingAs($user->fresh())->getJson('/ai/runs/'.$run->id)->assertForbidden();
        $this->assertFalse($authorization->allowsConversation($user->fresh(), $run->conversation_id));
    }

    public function test_module_page_navigation_and_login_fallback_are_available_without_business_access(): void
    {
        $user = $this->user(['view']);
        $this->actingAs($user)->get('/timebook')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Timebook/Index')->where('initial.totals.count', 0)
            ->where('nav', fn ($nav) => collect($nav)->contains(fn ($item) => $item['key'] === 'timebook' && $item['visible'])));
        $this->get('/objects/project')->assertForbidden();
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/timebook');
    }

    public function test_dedicated_operator_is_restricted_even_with_an_extra_admin_role(): void
    {
        config(['ai.harness_v2' => true]);
        app(SyncXycMetadata::class)->handle();
        $user = $this->user();
        $user->roles()->first()->update(['name' => 'timebook_operator']);
        $user->roles()->attach(Role::where('name', 'admin')->sole());
        $user = $user->fresh();
        $this->assertFalse($user->canDo('dashboard.view'));
        $this->assertFalse($user->canDo('object.project.view'));
        $this->actingAs($user)->get('/timebook')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.password_only', true)->where('notificationUnreadCount', 0)
            ->where('nav', fn ($nav) => collect($nav)->pluck('key')->all() === ['timebook', 'ai', 'settings']));
        $this->get('/ai')->assertOk();
        $this->get('/settings')->assertOk();
        foreach (['/', '/notifications', '/objects/project', '/objects/customer', '/admin/rbac', '/procurement-hub', '/ai/contracts', '/relation-options', '/purchase-request'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->putJson('/settings/email', ['email' => 'operator-new@example.test', 'current_password' => 'wrong'])->assertUnprocessable();
        $other = User::factory()->create();
        $this->putJson('/settings/email', ['email' => $other->email, 'current_password' => 'password'])->assertUnprocessable();
        $permissions = $user->permissionKeys();
        $this->put('/settings/email', ['email' => 'operator-new@example.test', 'current_password' => 'password', 'user_id' => $other->id])->assertRedirect('/settings');
        $this->assertSame('operator-new@example.test', $user->fresh()->email);
        $this->assertSame($other->email, $other->fresh()->email);
        $this->assertSame($permissions, $user->fresh()->permissionKeys());
        $this->put('/settings/password', ['current_password' => 'password', 'password' => 'Changed-Test-9876!', 'password_confirmation' => 'Changed-Test-9876!'])->assertRedirect('/settings');
        $this->assertTrue($user->fresh()->is_password_changed);
        $access = app(XycDataAccess::class);
        $this->assertSame([], $access->visibleObjects($user));
        foreach (BusinessObject::pluck('key') as $key) {
            $this->assertFalse($access->queryRecords($user, ['object' => $key])['ok']);
        }
        $this->assertTrue(json_decode((new QueryTimebookTool($user))->handle(new Request([])), true)['ok']);
        $this->post('/logout')->assertRedirect('/login');
        $ordinary = User::factory()->create();
        $ordinary->roles()->attach(Role::where('name', 'admin')->sole());
        $this->actingAs($ordinary)->get('/')->assertOk();
        $this->get('/notifications')->assertOk();
    }
}
