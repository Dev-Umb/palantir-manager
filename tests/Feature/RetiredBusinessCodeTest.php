<?php

namespace Tests\Feature;

use App\Actions\BuildAiUpdateProposal;
use App\Actions\BuildAiWriteProposal;
use App\Actions\BuildCompanyOperationsDashboard;
use App\Actions\CreateObjectRecord;
use App\Actions\SyncXycMetadata;
use App\Ai\Tools\PrepareObjectRecordCreateTool;
use App\Ai\Tools\PrepareObjectRecordUpdateTool;
use App\Ai\XycDataAccess;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RetiredBusinessCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_entry_points_are_absent_while_retained_routes_remain(): void
    {
        foreach (['requisitions.public.create', 'requisitions.public.material-options', 'requisitions.public.store', 'requisitions.create', 'requisitions.store', 'requisitions.approvals', 'requisitions.approve', 'requisitions.reject', 'team-logs.public.create', 'team-logs.public.store', 'team-logs.create', 'team-logs.store'] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }
        foreach (['objects.index', 'objects.store', 'records.update', 'objects.export', 'attachments.download', 'ai.index', 'quotations.index', 'hub.index'] as $name) {
            $this->assertTrue(Route::has($name), $name);
        }
        $this->get('/purchase-request')->assertNotFound();
        $this->get('/team-log/public')->assertNotFound();
    }

    public function test_metadata_sync_preserves_historical_records_and_does_not_restore_retired_definitions(): void
    {
        $legacy = BusinessObject::create(['key' => 'drawing', 'label' => '历史图纸', 'group' => '历史', 'code_prefix' => 'OLD', 'title_field' => 'name', 'fields' => [['key' => 'project_id', 'label' => '历史项目', 'type' => 'relation', 'target' => 'project'], ['key' => 'project_no', 'label' => '历史编号', 'type' => 'text']], 'roles' => [], 'read_only' => true]);
        $record = ObjectRecord::create(['business_object_id' => $legacy->id, 'code' => 'OLD-1', 'title' => '历史资料', 'payload' => ['remark' => '归档保留', 'attachment' => 'historical.pdf', 'project_no' => '归档原编号', 'project_id' => 'historical-project']]);
        $before = $record->payload;
        $this->seed(XycPrototypeSeeder::class);
        app(SyncXycMetadata::class)->handle();
        $this->assertSame($before, $record->fresh()->payload);
        $this->assertTrue($legacy->fresh()->read_only);
        $this->assertSame([], $legacy->fresh()->roles);
        $keys = array_column(config('xyc.objects'), 'key');
        $this->assertContains('project', $keys);
        $this->assertContains('contract', $keys);
        foreach (['drawing', 'material', 'production_team', 'team_member', 'requisition', 'inbound', 'outbound'] as $key) {
            $this->assertNotContains($key, $keys);
        }
        $this->assertSame(['customer', 'customer_contact'], BuildAiWriteProposal::WRITABLE_OBJECTS);
        $this->assertSame(['customer', 'customer_contact'], BuildAiUpdateProposal::UPDATABLE_OBJECTS);
    }

    public function test_historical_object_cannot_be_written_through_the_shared_writer(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $legacy = BusinessObject::create(['key' => 'material', 'label' => '历史材料', 'group' => '历史', 'code_prefix' => 'OLD', 'title_field' => 'name', 'fields' => [], 'roles' => [], 'read_only' => true]);
        $this->expectException(HttpException::class);
        app(CreateObjectRecord::class)->handle($legacy, ['name' => '禁止恢复']);
    }

    public function test_retained_customer_create_update_and_export_remain_authorized(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'admin')->firstOrFail());
        $customer = BusinessObject::where('key', 'customer')->firstOrFail();
        $record = app(CreateObjectRecord::class)->handle($customer, ['name' => '当前客户'], $user);
        $this->actingAs($user)->get('/objects/customer')->assertOk();
        $this->put('/records/'.$record->id, ['payload' => ['name' => '更新客户']])->assertRedirect();
        $this->assertSame('更新客户', $record->fresh()->title);
        $this->get('/objects/customer/export.csv')->assertOk();
    }

    public function test_ai_and_mcp_schema_exclude_retired_fields_and_keep_current_customer_fields(): void
    {
        $user = User::factory()->make();
        $schema = new JsonSchemaTypeFactory;
        foreach ([
            app(PrepareObjectRecordCreateTool::class, ['user' => $user]),
            app(PrepareObjectRecordUpdateTool::class, ['user' => $user]),
            app(\App\Mcp\Tools\PrepareObjectRecordCreateTool::class),
            app(\App\Mcp\Tools\PrepareObjectRecordUpdateTool::class),
        ] as $tool) {
            $definition = $tool->schema($schema);
            $this->assertSame(['customer', 'customer_contact'], $definition['object']->toArray()['enum']);
            $fields = $definition['payload']->toArray()['properties'];
            $this->assertArrayHasKey('name', $fields);
            $this->assertArrayHasKey('phone', $fields);
            foreach (['material_id', 'team_id', 'spec', 'completed_qty', 'unit_weight'] as $key) {
                $this->assertArrayNotHasKey($key, $fields);
            }
        }
    }

    public function test_archived_objects_are_not_exposed_to_ai_or_dashboard_even_for_admin(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'admin')->firstOrFail());
        $legacy = BusinessObject::create(['key' => 'shipment', 'label' => '历史发货', 'group' => '历史', 'code_prefix' => 'OLD', 'title_field' => 'name', 'fields' => [], 'roles' => [], 'read_only' => true]);
        $record = ObjectRecord::create(['business_object_id' => $legacy->id, 'code' => 'OLD-1', 'title' => '历史发货', 'payload' => ['qty_ton' => 999]]);
        $access = app(XycDataAccess::class);
        $this->assertNotContains('shipment', array_column($access->visibleObjects($user), 'key'));
        $this->assertFalse($access->queryRecords($user, ['object' => 'shipment'])['ok']);
        $dashboard = app(BuildCompanyOperationsDashboard::class)->handle($user);
        $this->assertArrayNotHasKey('production_delivery', $dashboard['cockpit']['panels']);
        $this->assertArrayHasKey('project_amounts', $dashboard['cockpit']['panels']);
        $this->assertSame(999, $record->fresh()->payload['qty_ton']);
    }
}
