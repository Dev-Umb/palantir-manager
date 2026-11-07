<?php

namespace Tests\Feature;

use App\Models\FinanceShipmentLedger;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use App\Support\ProjectVisibility;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class FinanceUnlinkedLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(XycPrototypeSeeder::class);
        $this->finance = User::where('email', 'finance@xyc.test')->sole();
        Storage::fake('local');
        $this->actingAs($this->finance);
    }

    public function test_overview_checks_each_project_write_permission_only_once(): void
    {
        $projects = ObjectRecord::whereRelation('businessObject', 'key', 'project')->get();
        FinanceShipmentLedger::factory()->create(['project_id' => $projects->first()->id]);
        $this->partialMock(ProjectVisibility::class, function (MockInterface $mock) use ($projects): void {
            $mock->shouldReceive('allowsRecordWrite')->times($projects->count())->passthru();
        });
        $this->get(route('finance-shipments.index'))->assertOk();
    }

    public function test_display_title_is_finance_only_and_keeps_original_source_and_association(): void
    {
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
        $before = ObjectRecord::orderBy('id')->get()->toArray();
        $ledger = FinanceShipmentLedger::factory()->create(['project_id' => $project->id, 'title' => 'source.xls']);
        $orders = $ledger->orders;
        $url = route('finance-shipments.ledgers.rename', $ledger);
        $this->putJson($url, ['revision' => 0, 'display_title' => '财务新名称'])->assertOk()->assertJsonPath('revision', 1);
        $this->get(route('finance-shipments.index'))->assertInertia(fn (Assert $page) => $page->where('projects', fn ($rows) => collect($rows)->contains(fn ($row) => ($row['ledger_id'] ?? null) === $ledger->id && $row['title'] === '财务新名称' && $row['project_title'] === $project->title)));
        $this->get(route('finance-shipments.show', $project))->assertInertia(fn (Assert $page) => $page->where('project.title', '财务新名称'));
        $this->putJson($url, ['revision' => 0, 'display_title' => '过期修改'])->assertConflict();
        foreach (['', '   ', str_repeat('字', 256)] as $invalid) {
            $this->putJson($url, ['revision' => 1, 'display_title' => $invalid])->assertUnprocessable();
        }
        $ledger->refresh();
        $this->assertSame('source.xls', $ledger->title);
        $this->assertSame($project->id, $ledger->project_id);
        $this->assertSame($orders, $ledger->orders);
        $this->assertSame($before, ObjectRecord::orderBy('id')->get()->toArray());
        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.shipments.rename', 'subject_id' => $ledger->id]);
        $this->putJson(route('finance-shipments.ledgers.associate', $ledger), ['revision' => 0, 'project_id' => null])->assertConflict();
        $this->putJson(route('finance-shipments.ledgers.associate', $ledger), ['revision' => 1, 'project_id' => null])->assertOk();
        $this->get(route('finance-shipments.ledgers.show', $ledger))->assertInertia(fn (Assert $page) => $page->where('project.title', '财务新名称'));
    }

    public function test_rename_supports_new_and_unlinked_ledgers_without_business_writes(): void
    {
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
        $before = $project->getRawOriginal();
        $this->putJson(route('finance-shipments.rename', $project), ['revision' => 0, 'display_title' => '仅财务名称'])->assertOk();
        $ledger = FinanceShipmentLedger::where('project_id', $project->id)->sole();
        $this->assertSame([], $ledger->orders);
        $this->assertSame($before, $project->fresh()->getRawOriginal());
        $other = FinanceShipmentLedger::factory()->create(['title' => 'unlinked.xls']);
        $this->putJson(route('finance-shipments.ledgers.rename', $other), ['revision' => 0, 'display_title' => '独立资料'])->assertOk();
        $this->assertNull($other->fresh()->project_id);
        $this->assertSame('unlinked.xls', $other->fresh()->title);
    }

    public function test_rename_preserves_permission_boundaries(): void
    {
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
        $ledger = FinanceShipmentLedger::factory()->create();
        $input = ['revision' => 0, 'display_title' => '不应保存'];
        $this->actingAs(User::factory()->create())->putJson(route('finance-shipments.ledgers.rename', $ledger), $input)->assertForbidden();
        $role = Role::firstOrCreate(['name' => 'business_manager_view'], ['label' => '业务经理']);
        $this->finance->roles()->attach($role);
        $this->actingAs($this->finance->fresh())->putJson(route('finance-shipments.ledgers.rename', $ledger), $input)->assertForbidden();
        $this->putJson(route('finance-shipments.rename', $project), $input)->assertForbidden();
        $this->get(route('finance-shipments.index'))->assertInertia(fn (Assert $page) => $page->where('projects', fn ($rows) => collect($rows)->every(fn ($row) => empty($row['rename_url']))));
        $this->assertNull($ledger->fresh()->display_title);
    }

    public function test_unlinked_import_relink_and_unlink_preserve_data_and_files(): void
    {
        $before = ObjectRecord::orderBy('id')->get()->toArray();
        $ledger = FinanceShipmentLedger::factory()->create(['title' => '待匹配来源.xls']);
        $file = UploadedFile::fake()->createWithContent('发货.csv', "发货日期,车号,货物名称,数量,单位,单价\n2026.11.01,晋A,模板,10,吨,5000\n2026.11.02,晋B,盖板,5,,5000\n2026.11.03,收款,模板,10,吨,5000\n");
        $preview = $this->postJson(route('finance-shipments.ledgers.preview', $ledger), ['file' => $file])->assertOk()->json();
        $saved = $this->postJson(route('finance-shipments.ledgers.commit', $ledger), ['id' => $preview['id']])
            ->assertOk()->assertJsonPath('summary.tonnes', 15)->assertJsonPath('summary.pending', 0)->assertJsonCount(2, 'orders')->json();
        $this->get(route('finance-shipments.ledgers.show', $ledger))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('project.owner_id', 'unlinked')->has('ledger.orders', 2));
        $this->get(route('finance-shipments.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canUnlink', true)->where('projects', fn ($rows) => collect($rows)->contains(fn ($row) => ($row['ledger_id'] ?? null) === $ledger->id && $row['owner_id'] === 'unlinked')));
        $path = DB::table('finance_shipment_imports')->value('path');
        $projects = ObjectRecord::whereRelation('businessObject', 'key', 'project')->take(2)->get();
        $other = FinanceShipmentLedger::factory()->create(['project_id' => $projects[1]->id]);
        $revision = $saved['revision'];
        foreach ([$projects[0]->id, $projects[1]->id, null] as $target) {
            $this->putJson(route('finance-shipments.ledgers.associate', $ledger), ['revision' => $revision, 'project_id' => $target])
                ->assertOk()->assertJsonPath('project_id', $target)->assertJsonPath('revision', ++$revision);
            $this->assertSame($saved['orders'], $ledger->fresh()->orders);
            $this->assertSame($before, ObjectRecord::orderBy('id')->get()->toArray());
        }
        $this->assertSame([], $other->fresh()->orders);
        $this->assertSame($path, DB::table('finance_shipment_imports')->value('path'));
        Storage::disk('local')->assertExists($path);
        $this->postJson(route('finance-shipments.ledgers.preview', $ledger), ['file' => $file])->assertOk()->assertJsonPath('id', $preview['id']);
        $this->postJson(route('finance-shipments.ledgers.commit', $ledger), ['id' => $preview['id']])->assertOk()->assertJsonCount(2, 'orders');
        $this->assertDatabaseCount('finance_shipment_imports', 1);
    }

    public function test_conflicts_invalid_targets_and_forbidden_accounts_cannot_change_association(): void
    {
        $ledger = FinanceShipmentLedger::factory()->create(['revision' => 2]);
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
        $customer = ObjectRecord::whereRelation('businessObject', 'key', 'customer')->firstOrFail();
        $url = route('finance-shipments.ledgers.associate', $ledger);
        $this->putJson($url, ['revision' => 1, 'project_id' => $project->id])->assertConflict();
        $this->putJson($url, ['revision' => 2, 'project_id' => $customer->id])->assertForbidden();
        $this->putJson($url, ['revision' => 2, 'project_id' => 'not-a-uuid'])->assertUnprocessable();
        $this->actingAs(User::factory()->create())->putJson($url, ['revision' => 2, 'project_id' => $project->id])->assertForbidden();
        $this->get(route('finance-shipments.ledgers.show', $ledger))->assertForbidden();
        $this->assertNull($ledger->fresh()->project_id);
        $this->assertSame(2, $ledger->fresh()->revision);
    }

    public function test_manager_combination_cannot_see_unlinked_or_reassign_outside_write_scope(): void
    {
        $role = Role::firstOrCreate(['name' => 'business_manager_view'], ['label' => '业务经理']);
        $this->finance->roles()->attach($role);
        $ledger = FinanceShipmentLedger::factory()->create();
        $this->get(route('finance-shipments.ledgers.show', $ledger))->assertForbidden();
        $this->get(route('finance-shipments.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canUnlink', false)->where('projects', fn ($rows) => ! collect($rows)->contains('owner_id', 'unlinked')));
        $projects = ObjectRecord::whereRelation('businessObject', 'key', 'project')->take(2)->get();
        $projects[0]->update(['payload' => [...$projects[0]->payload, 'business_owner_user_id' => $this->finance->id]]);
        $ledger->update(['project_id' => $projects[0]->id]);
        $this->putJson(route('finance-shipments.ledgers.associate', $ledger), ['revision' => 0, 'project_id' => $projects[1]->id])->assertForbidden();
        $this->putJson(route('finance-shipments.ledgers.associate', $ledger), ['revision' => 0, 'project_id' => null])->assertForbidden();
    }

    public function test_association_invalidates_stale_detail_save_and_keeps_legacy_route(): void
    {
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
        $ledger = FinanceShipmentLedger::factory()->create(['project_id' => $project->id]);
        $other = FinanceShipmentLedger::factory()->create(['project_id' => $project->id]);
        $this->get(route('finance-shipments.show', $project))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('urls.save', route('finance-shipments.ledgers.save', $ledger)));
        $this->putJson(route('finance-shipments.ledgers.associate', $ledger), ['revision' => 0, 'project_id' => null])->assertOk();
        $order = ['id' => (string) Str::uuid(), 'rows' => []];
        $this->putJson(route('finance-shipments.ledgers.save', $ledger), ['revision' => 0, 'orders' => [$order]])->assertConflict();
        $this->assertSame([], $ledger->fresh()->orders);
        $this->get(route('finance-shipments.ledgers.show', $other))->assertOk();
    }

    public function test_historical_import_backfill_keeps_original_orders_paths_and_business_records(): void
    {
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
        $ledger = FinanceShipmentLedger::factory()->create(['project_id' => $project->id]);
        $id = (string) Str::uuid();
        DB::table('finance_shipment_imports')->insert(['id' => $id, 'project_id' => $project->id, 'user_id' => $this->finance->id,
            'hash' => str_repeat('a', 64), 'name' => 'legacy.xls', 'path' => 'original/legacy.xls', 'preview' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $before = $ledger->fresh()->getRawOriginal();
        $migration = require database_path('migrations/2026_11_07_075347_backfill_finance_shipment_import_ledgers.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('finance_shipment_imports', ['id' => $id, 'ledger_id' => $ledger->id, 'path' => 'original/legacy.xls']);
        $this->assertSame($before, $ledger->fresh()->getRawOriginal());
        $this->assertDatabaseCount('finance_shipment_ledgers', 1);
    }
}
