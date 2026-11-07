<?php

namespace Tests\Feature;

use App\Actions\SaveFinanceShipments;
use App\Models\AuditLog;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\FinanceShipmentImport;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinanceShipmentTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private ObjectRecord $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(XycPrototypeSeeder::class);
        $this->finance = User::where('email', 'finance@xyc.test')->sole();
        $this->project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
    }

    private function order(array $row = []): array
    {
        return ['id' => (string) Str::uuid(), 'date' => '2026-11-06', 'vehicle' => '晋A12345', 'source' => '', 'rows' => [[
            'id' => (string) Str::uuid(), 'name' => '盖梁模板', 'unit' => '吨', 'quantity' => 12.345, 'price' => 5000, 'note' => '', ...$row,
        ]]];
    }

    public function test_finance_saves_aggregates_without_changing_any_project_fields(): void
    {
        $before = $this->project->getRawOriginal();
        $order = $this->order();
        $nonTon = $this->order(['unit' => '件', 'quantity' => 20]);
        $draft = $this->order(['quantity' => null]);
        $this->actingAs($this->finance)->putJson(route('finance-shipments.save', $this->project), ['revision' => 0, 'orders' => [$order, $nonTon, $draft]])
            ->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('summary.tonnes', 12.345)->assertJsonPath('summary.pending', 2);
        $this->assertSame($before, $this->project->fresh()->getRawOriginal());
        $this->assertSame(1, AuditLog::where('action', 'finance.shipments.save')->count());
        $this->actingAs($this->finance)->get(route('finance-shipments.show', $this->project))->assertOk()->assertInertia(fn (Assert $page) => $page->component('FinanceShipments/Show')->has('ledger.orders', 3));
    }

    public function test_business_and_unprivileged_accounts_cannot_read_or_write(): void
    {
        foreach ([User::where('email', 'business@xyc.test')->sole(), User::factory()->create()] as $user) {
            $this->actingAs($user)->get(route('finance-shipments.index'))->assertForbidden();
            $this->putJson(route('finance-shipments.save', $this->project), ['revision' => 0, 'orders' => [$this->order()]])->assertForbidden();
        }
        $this->assertDatabaseCount('finance_shipment_ledgers', 0);
    }

    public function test_stale_version_and_invalid_values_do_not_mutate(): void
    {
        $this->actingAs($this->finance);
        $order = $this->order();
        $before = $this->project->getRawOriginal();
        $url = route('finance-shipments.save', $this->project);
        $this->putJson($url, ['revision' => 0, 'orders' => [$order]])->assertOk();
        $order['rows'][0]['quantity'] = 99;
        $this->putJson($url, ['revision' => 0, 'orders' => [$order]])->assertConflict();
        $order['rows'][0]['quantity'] = -1;
        $this->putJson($url, ['revision' => 1, 'orders' => [$order]])->assertUnprocessable();
        $this->assertSame($before, $this->project->fresh()->getRawOriginal());
        $this->putJson($url, ['revision' => 1, 'orders' => []])->assertOk()->assertJsonPath('summary.tonnes', 0);
        $this->assertSame($before, $this->project->fresh()->getRawOriginal());
    }

    public function test_first_draft_preserves_historical_totals_and_other_objects_are_rejected(): void
    {
        $before = $this->project->payload;
        $this->actingAs($this->finance)->putJson(route('finance-shipments.save', $this->project), ['revision' => 0, 'orders' => [$this->order(['quantity' => null])]])->assertOk();
        $this->assertSame($before, $this->project->fresh()->payload);
        $customer = ObjectRecord::whereRelation('businessObject', 'key', 'customer')->firstOrFail();
        $this->putJson(route('finance-shipments.save', $customer), ['revision' => 0, 'orders' => []])->assertForbidden();
    }

    public function test_import_classifies_invoice_totals_unknown_units_and_non_tonnes(): void
    {
        $result = app(FinanceShipmentImport::class)->classify([['name' => 'Sheet1', 'rows' => [
            ['发货日期', '车号', '货物名称', '数量', '计量单位', '单价'],
            ['2026.11.01', '晋A', '模板', 10, '吨', 5000],
            ['2026.11.01', '开票', '模板', 10, '吨', 5000],
            ['', '', '小计', 10, '吨', ''],
            ['2026.11.02', '晋A', '模板', 15, '', 5000],
            ['2026.11.02', '晋A', '配件', 15, '件', 50],
        ]]], 'test.xlsx');
        $this->assertSame(['ready' => 1, 'pending' => 2, 'excluded' => 2], $result['counts']);
    }

    public function test_csv_preview_and_idempotent_confirm_use_server_snapshot(): void
    {
        Storage::fake('local');
        $before = $this->project->getRawOriginal();
        $file = UploadedFile::fake()->createWithContent('发货.csv', "发货日期,车号,货物名称,吨数,单价\n2026.11.01,晋A,模板,10,5000\n2026.11.02,开票,模板,10,5000\n");
        $preview = $this->actingAs($this->finance)->postJson(route('finance-shipments.preview', $this->project), ['file' => $file])->assertOk()->assertJsonPath('counts.ready', 1)->json();
        $url = route('finance-shipments.commit', $this->project);
        $this->postJson($url, ['id' => $preview['id'], 'orders' => [$this->order(['quantity' => 999])]])->assertOk()->assertJsonPath('summary.tonnes', 10);
        $this->postJson($url, ['id' => $preview['id']])->assertOk()->assertJsonCount(1, 'orders');
        $this->assertSame($before, $this->project->fresh()->getRawOriginal());
        $path = DB::table('finance_shipment_imports')->value('path');
        Storage::disk('local')->assertExists($path);
    }

    public function test_modified_file_does_not_duplicate_existing_shipments_and_source_is_protected(): void
    {
        Storage::fake('local');
        $this->actingAs($this->finance);
        foreach (['', "\n"] as $extra) {
            $file = UploadedFile::fake()->createWithContent('发货.csv', "发货日期,车号,货物名称,吨数,单价\n2026.11.01,晋A,模板,10,5000\n".$extra);
            $preview = $this->postJson(route('finance-shipments.preview', $this->project), ['file' => $file])->assertOk()->json();
            $this->postJson(route('finance-shipments.commit', $this->project), ['id' => $preview['id']])->assertOk()->assertJsonCount(1, 'orders');
        }
        $this->assertSame(1, $preview['counts']['pending']);
        $current = app(SaveFinanceShipments::class)->current($this->project);
        $source = $current['orders'][0]['source'];
        $current['orders'][0]['source'] = '伪造来源';
        $this->putJson(route('finance-shipments.save', $this->project), ['revision' => $current['revision'], 'orders' => $current['orders']])
            ->assertOk()->assertJsonPath('orders.0.source', $source);
    }

    public function test_ledger_dates_are_independent_of_business_dates(): void
    {
        $before = $this->project->payload;
        $before['first_shipment_date'] = '2026-11-07';
        $this->project->update(['payload' => $before]);
        $this->actingAs($this->finance)->putJson(route('finance-shipments.save', $this->project), ['revision' => 0, 'orders' => [$this->order()]])->assertOk()->assertJsonPath('summary.last_date', '2026-11-06');
        $this->assertSame($before, $this->project->fresh()->payload);
        $this->assertDatabaseCount('finance_shipment_ledgers', 1);
    }

    public function test_pending_shipments_are_visible_without_guessing_units_or_dates(): void
    {
        Storage::fake('local');
        $before = $this->project->getRawOriginal();
        $file = UploadedFile::fake()->createWithContent('待确认.csv', "编号,发货日期,车号,货物名称,数量,单位,单价\n1,2026.11.01,晋A,盖板,16.94,,5000\n2,,,,,,\n3,,晋B,模板,3,吨,4000\n4,2026.11.02,晋C,配件,2,件,50\n5,2026.11.02,开票,模板,10,吨,5000\n6,2026.11.03,晋D,退货模板,5,吨,5000\n7,2026.11.01,晋A,盖板,16.94,,5000\n");
        $preview = $this->actingAs($this->finance)->postJson(route('finance-shipments.preview', $this->project), ['file' => $file])
            ->assertOk()->assertJsonPath('importable_count', 3)->assertJsonPath('draft_count', 3)->assertJsonPath('counts.excluded', 2)->json();
        $saved = $this->postJson(route('finance-shipments.commit', $this->project), ['id' => $preview['id']])
            ->assertOk()->assertJsonCount(3, 'orders')->assertJsonPath('summary.tonnes', 0)->assertJsonPath('summary.pending', 3)
            ->assertJsonPath('orders.0.rows.0.unit', null)->assertJsonPath('orders.0.rows.0.quantity', 16.94)
            ->assertJsonPath('orders.1.date', null)->assertJsonPath('import.pending_outside_ledger', 2)->json();
        $this->postJson(route('finance-shipments.commit', $this->project), ['id' => $preview['id']])->assertOk()->assertJsonCount(3, 'orders');
        $this->assertSame($before, $this->project->fresh()->getRawOriginal());
    }

    public function test_supplement_is_idempotent_and_preserves_existing_edits_and_business_data(): void
    {
        Storage::fake('local');
        $before = $this->project->getRawOriginal();
        $file = UploadedFile::fake()->createWithContent('历史.csv', "发货日期,车号,货物名称,数量,单位,单价\n2026.11.01,晋A,模板,10,吨,5000\n2026.11.02,晋B,盖板,16.94,,5000\n");
        $service = app(FinanceShipmentImport::class);
        $preview = $service->preview($this->project, $file->getRealPath(), '历史.csv', $this->finance);
        $order = $this->order(['quantity' => 12]);
        $order['source'] = $preview['records'][0]['source'];
        app(SaveFinanceShipments::class)->save($this->project, ['revision' => 0, 'orders' => [$order]], $this->finance, trustedImport: true);
        DB::table('finance_shipment_imports')->where('id', $preview['id'])->update(['imported_at' => now()]);
        $saved = $service->commit($this->project, $preview['id'], $this->finance, supplementPending: true);
        $this->assertCount(2, $saved['orders']);
        $this->assertEquals(12, $saved['orders'][0]['rows'][0]['quantity']);
        $this->assertEquals(12, $saved['summary']['tonnes']);
        $this->assertSame(0, $saved['import']['pending_outside_ledger']);
        $repeated = $service->commit($this->project, $preview['id'], $this->finance, supplementPending: true);
        $this->assertSame($saved['revision'], $repeated['revision']);
        $this->assertSame($saved['orders'], $repeated['orders']);
        $this->assertSame($before, $this->project->fresh()->getRawOriginal());
    }

    public function test_row_dates_and_vehicles_are_independent_and_documents_are_not_split_by_vehicle(): void
    {
        $before = $this->project->getRawOriginal();
        $first = $this->order(['date' => '2026-11-01', 'vehicle' => '晋B']);
        $first['source'] = '发货.xls / Sheet1 / 第3行';
        $second = $this->order(['date' => null, 'vehicle' => '晋C', 'quantity' => 2]);
        $second['source'] = '发货.xls / Sheet1 / 第4行';
        $first['rows'][] = $second['rows'][0];
        $writer = app(SaveFinanceShipments::class);
        $saved = $writer->save($this->project, ['revision' => 0, 'orders' => [$first]], $this->finance, trustedImport: true);
        $this->assertSame('2026-11-01', $saved['summary']['last_date']);
        $this->assertEquals(12.345, $saved['summary']['tonnes']);
        $this->assertEquals(71725, $saved['summary']['amount']);
        $this->assertSame('晋B', $saved['orders'][0]['rows'][0]['vehicle']);
        $this->assertSame('晋C', $saved['orders'][0]['rows'][1]['vehicle']);
        $this->assertNull($saved['orders'][0]['rows'][1]['date']);
        $this->assertSame(1, $writer->documentCount([$first, $second]));
        $this->assertSame($before, $this->project->fresh()->getRawOriginal());
    }
}
