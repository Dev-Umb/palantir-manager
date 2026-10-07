<?php

namespace Tests\Feature;

use App\Actions\SyncObjectReferenceNames;
use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\QuotationArchive;
use App\Models\Role;
use App\Models\User;
use App\Support\BusinessWorkspace;
use App\Support\ObjectRelations;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ObjectReferenceNameSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(XycPrototypeSeeder::class);
    }

    public function test_scope_matches_the_current_online_business_workspace(): void
    {
        $this->assertSame(realpath(dirname(__DIR__, 2).'/app/Models/ObjectRecord.php'), (new \ReflectionClass(ObjectRecord::class))->getFileName());
        if (class_exists(BusinessWorkspace::class)) {
            $this->assertSame(BusinessWorkspace::RETAINED_OBJECT_KEYS, SyncObjectReferenceNames::OBJECT_KEYS);
        }
        $sync = app(SyncObjectReferenceNames::class);
        foreach (BusinessObject::all() as $object) {
            $this->assertSame(in_array($object->key, ['customer', 'customer_contact', 'tender', 'project', 'project_business_summary', 'contract'], true), $sync->supports($object));
        }
    }

    public function test_project_rename_syncs_contract_and_tender_but_preserves_other_projects_archives_and_retired_records(): void
    {
        $project = $this->record('project', '原项目');
        $other = $this->record('project', '其他项目');
        $contract = $this->record('contract', '合同', ['project_id' => $project->id, 'amount' => 1200, 'status' => '已签署', 'remark' => '保留']);
        $tender = $this->record('tender', '招投标', ['converted_project_id' => $project->id]);
        $untouched = $this->record('contract', '其他合同', ['project_id' => $other->id, 'amount' => 0]);
        $retired = $this->record('drawing', '废弃历史记录', ['project_id' => $project->id, 'project_no' => 'OLD',
            '_snapshots' => ['project_id' => ['id' => $project->id, 'label' => '原项目']]]);
        $untouchedPayload = $untouched->payload;
        $retiredPayload = $retired->payload;
        $archive = QuotationArchive::factory()->create(['snapshot' => ['params' => ['project_id' => $project->id, 'project_name' => '原项目']]]);
        $audit = AuditLog::create(['action' => 'test.archive', 'subject_type' => 'project', 'subject_id' => $project->id, 'payload' => ['name' => '原项目']]);
        $project->update(['title' => '新项目', 'payload' => ['name' => '新项目', 'project_no' => 'NEW-001']]);
        $saved = $contract->fresh(['businessObject']);
        $this->assertSame('新项目', $saved->payload['_snapshots']['project_id']['label']);
        $this->assertSame('NEW-001', $saved->payload['project_no']);
        $this->assertSame('新项目', app(ObjectRelations::class)->formatRecord($saved)['display']['project_id']);
        $this->assertSame('新项目', $tender->fresh()->payload['_snapshots']['converted_project_id']['label']);
        $this->assertSame($project->id, $saved->payload['project_id']);
        $this->assertSame(1200, $saved->payload['amount']);
        $this->assertSame('已签署', $saved->payload['status']);
        $this->assertSame('保留', $saved->payload['remark']);
        $this->assertSame($untouchedPayload, $untouched->fresh()->payload);
        $this->assertSame($retiredPayload, $retired->fresh()->payload);
        $this->assertSame('原项目', $archive->fresh()->snapshot['params']['project_name']);
        $this->assertSame(['name' => '原项目'], $audit->fresh()->payload);
    }

    public function test_customer_and_contact_names_sync_within_retained_objects_and_stale_form_cannot_restore_old_names(): void
    {
        $customer = $this->record('customer', '旧客户');
        $contact = $this->record('customer_contact', '旧联系人', ['customer_id' => $customer->id, 'name' => '旧联系人', 'phone' => '10086']);
        $project = $this->record('project', '项目', ['customer_id' => $customer->id, 'customer_contact_ids' => [$contact->id]]);
        $contract = $this->record('contract', '合同', ['project_id' => $project->id, 'customer_id' => $customer->id]);
        $tender = $this->record('tender', '招投标', ['customer_id' => $customer->id]);
        $old = $project->payload;
        $customer->update(['title' => '新客户', 'payload' => ['name' => '新客户']]);
        $contact->update(['title' => '新联系人', 'payload' => [...$contact->payload, 'name' => '新联系人']]);
        $project->update(['payload' => $old]);
        foreach ([$project, $contract, $tender, $contact] as $record) {
            $this->assertSame('新客户', $record->fresh()->payload['_snapshots']['customer_id']['label']);
            $this->assertSame($customer->id, $record->fresh()->payload['customer_id']);
        }
        $this->assertSame('新联系人', $project->fresh()->payload['_snapshots']['customer_contact_ids'][0]['label']);
        $relations = app(ObjectRelations::class);
        $saved = $project->fresh(['businessObject']);
        $relations->preloadLabels(collect([$saved]));
        $this->assertSame(['新联系人(10086)'], $relations->formatRecord($saved)['display']['customer_contact_ids']);
    }

    public function test_command_previews_repairs_and_is_idempotent_without_repairing_retired_modules(): void
    {
        $project = $this->record('project', '当前项目');
        $contract = $this->record('contract', '合同', ['project_id' => $project->id, 'amount' => 100]);
        $stale = [...$contract->payload, 'project_no' => 'OLD', '_snapshots' => ['project_id' => ['id' => $project->id, 'label' => '旧项目']]];
        $contract->newQuery()->whereKey($contract->id)->update(['payload' => $stale]);
        $invalid = $this->record('contract', '无效引用', ['project_id' => 'missing', '_snapshots' => ['project_id' => ['id' => 'missing', 'label' => '保留线索']]]);
        $retired = $this->record('drawing', '废弃记录', ['project_id' => $project->id, 'project_no' => 'OLD']);
        $before = $retired->payload;
        $this->artisan('xyc:sync-reference-names')->expectsOutputToContain('仅预览：变更')->assertSuccessful();
        $this->assertSame($stale, $contract->fresh()->payload);
        $this->artisan('xyc:sync-reference-names --apply')->assertSuccessful();
        $this->assertSame('当前项目', $contract->fresh()->payload['_snapshots']['project_id']['label']);
        $this->assertSame(100, $contract->fresh()->payload['amount']);
        $this->assertSame('保留线索', $invalid->fresh()->payload['_snapshots']['project_id']['label']);
        $this->assertSame($before, $retired->fresh()->payload);
        $count = AuditLog::where('action', 'object.references.sync')->count();
        $this->artisan('xyc:sync-reference-names --apply')->expectsOutputToContain('修复完成：变更 0 条')->assertSuccessful();
        $this->assertSame($count, AuditLog::where('action', 'object.references.sync')->count());
    }

    public function test_source_and_dependents_roll_back_when_sync_fails(): void
    {
        $project = $this->record('project', '原项目');
        $contract = $this->record('contract', '合同', ['project_id' => $project->id]);
        $before = $contract->payload;
        DB::unprepared("CREATE TRIGGER fail_reference_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'object.references.sync' BEGIN SELECT RAISE(ABORT, '同步失败'); END");
        try {
            $project->update(['title' => '不应保存']);
            $this->fail('Expected rollback');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('同步失败', $exception->getMessage());
        }
        $this->assertSame('原项目', $project->fresh()->title);
        $this->assertSame($before, $contract->fresh()->payload);
    }

    public function test_http_rename_updates_contract_search_detail_export_and_preserves_permissions(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());
        $this->actingAs($admin);
        $customer = $this->record('customer', '检索客户');
        $project = $this->record('project', '旧检索项目', ['customer_id' => $customer->id]);
        $contract = $this->record('contract', '合同检索', ['project_id' => $project->id, 'customer_id' => $customer->id, 'amount' => 100]);
        $this->putJson("/records/{$project->id}", ['payload' => ['name' => '全新检索项目', 'customer_id' => $customer->id]])->assertOk();
        $this->assertSame('全新检索项目', $contract->fresh()->payload['_snapshots']['project_id']['label']);
        $this->get('/objects/contract?q='.rawurlencode('全新检索项目'))->assertOk();
        $this->get("/objects/contract?record={$contract->id}&mode=detail")->assertOk();
        $csv = $this->get('/objects/contract/export.csv?q='.rawurlencode('全新检索项目'))->assertOk()->streamedContent();
        $this->assertStringContainsString('全新检索项目', $csv);
        $this->assertStringNotContainsString('旧检索项目', $csv);
        $this->actingAs(User::factory()->create());
        $this->putJson("/records/{$project->id}", ['payload' => ['name' => '越权修改']])->assertForbidden();
        $this->assertSame('全新检索项目', $project->fresh()->title);
    }

    public function test_clearing_relations_and_invalid_types_preserves_zero_amount_and_invalid_evidence(): void
    {
        $project = $this->record('project', '项目');
        $contract = $this->record('contract', '合同', ['project_id' => $project->id, 'amount' => 0]);
        $contract->update(['payload' => [...$contract->payload, 'project_id' => null]]);
        $this->assertArrayNotHasKey('project_id', $contract->fresh()->payload['_snapshots'] ?? []);
        $this->assertSame('', $contract->fresh()->payload['project_no']);
        $this->assertSame(0, $contract->fresh()->payload['amount']);
        $customer = $this->record('customer', '客户');
        $invalid = $this->record('contract', '错误类型', ['project_id' => $customer->id, '_snapshots' => ['project_id' => ['id' => $customer->id, 'label' => '待复核']]]);
        $this->assertSame(['project_id'], app(SyncObjectReferenceNames::class)->refreshRecord($invalid, false)['invalid']);
        $this->assertSame('待复核', $invalid->fresh()->payload['_snapshots']['project_id']['label']);
    }

    private function record(string $key, string $name, array $payload = []): ObjectRecord
    {
        if ($key === 'drawing') {
            BusinessObject::firstOrCreate(['key' => 'drawing'], ['label' => '历史归档', 'group' => '历史归档', 'code_prefix' => 'OLD', 'title_field' => 'name', 'roles' => [], 'fields' => [], 'read_only' => true]);
        }

        return ObjectRecord::create([
            'business_object_id' => BusinessObject::where('key', $key)->firstOrFail()->id,
            'code' => strtoupper($key).'-'.Str::random(8), 'title' => $name, 'payload' => $payload,
        ]);
    }
}
