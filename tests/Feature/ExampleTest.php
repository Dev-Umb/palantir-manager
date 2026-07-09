<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Actions\CreateObjectRecord;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_registration_assigns_basic_role_only(): void
    {
        $this->post('/register', [
            'name' => '基础用户',
            'email' => 'basic@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect('/');

        $this->assertAuthenticated();
        $user = auth()->user()->fresh();

        $this->assertTrue($user->roles()->where('name', 'basic')->exists());
        $this->assertTrue($user->canDo('dashboard.view'));
        $this->assertTrue($user->canDo('requisition.create'));
        $this->assertFalse($user->canDo('rbac.manage'));
        $this->assertFalse($user->canDo('object.project.view'));
    }

    public function test_basic_role_cannot_access_rbac_or_ontology(): void
    {
        $this->post('/register', [
            'name' => '基础用户',
            'email' => 'basic2@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->get('/')->assertOk();
        $this->get('/requests/create')->assertOk();
        $this->get('/admin/rbac')->assertForbidden();
        $this->get('/objects/project')->assertForbidden();
    }

    public function test_admin_can_access_rbac_and_objects(): void
    {
        $this->artisan('xyc:admin', [
            'email' => 'admin@example.com',
            '--password' => 'password123',
        ])->assertSuccessful();

        $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'password123',
        ]);

        $this->assertTrue(Role::where('name', 'admin')->exists());
        $this->get('/admin/rbac')->assertOk();
        $this->get('/objects/project')->assertOk();
    }

    public function test_dashboard_exposes_four_operator_friendly_boards(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->artisan('xyc:admin', [
            'email' => 'admin@example.com',
            '--password' => 'password123',
        ]);

        $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'password123',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('boards', 4)
                ->where('boards.0.title', '经营大盘')
                ->where('boards.1.title', '库存大盘')
                ->where('boards.2.title', '采购大盘')
                ->where('boards.3.title', '财务大盘')
                ->has('projectFlows', 1)
                ->where('projectFlows.0.current_step', '生产')
                ->where('projectFlows.0.steps.4.label', '生产')
                ->where('projectFlows.0.steps.4.status', 'current'));
    }

    public function test_dashboard_uses_a_bounded_number_of_queries(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->actingAs($this->userWithRole('admin'));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get('/')->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(12, $queryCount, "Dashboard used {$queryCount} queries.");
    }

    public function test_object_lists_batch_load_relation_labels(): void
    {
        $this->seed(XycPrototypeSeeder::class);

        $customerObject = BusinessObject::where('key', 'customer')->firstOrFail();
        $projectObject = BusinessObject::where('key', 'project')->firstOrFail();

        foreach (range(1, 8) as $index) {
            $customer = ObjectRecord::create([
                'business_object_id' => $customerObject->id,
                'code' => "CUST-N{$index}",
                'title' => "查询客户{$index}",
                'payload' => ['name' => "查询客户{$index}"],
            ]);

            ObjectRecord::create([
                'business_object_id' => $projectObject->id,
                'code' => "PRJ-N{$index}",
                'title' => "查询项目{$index}",
                'payload' => [
                    'name' => "查询项目{$index}",
                    'project_no' => "N{$index}",
                    'customer_id' => $customer->id,
                    'stage' => '生产加工',
                ],
            ]);
        }

        $this->actingAs($this->userWithRole('admin'));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get('/objects/project')->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(12, $queryCount, "Project list used {$queryCount} queries.");
    }

    public function test_procurement_approvals_batch_load_relation_labels(): void
    {
        $this->seed(XycPrototypeSeeder::class);

        $materialObject = BusinessObject::where('key', 'material')->firstOrFail();
        $requisitionObject = BusinessObject::where('key', 'requisition')->firstOrFail();

        foreach (range(1, 8) as $index) {
            $material = ObjectRecord::create([
                'business_object_id' => $materialObject->id,
                'code' => "MAT-N{$index}",
                'title' => "查询物料{$index}",
                'payload' => ['name' => "查询物料{$index}"],
            ]);

            ObjectRecord::create([
                'business_object_id' => $requisitionObject->id,
                'code' => "QG-N{$index}",
                'title' => "查询申请{$index}",
                'payload' => [
                    'requester' => '生产',
                    'material_id' => $material->id,
                    'qty' => $index,
                    'unit' => '吨',
                    'urgency' => '普通',
                    'status' => '待处理',
                ],
            ]);
        }

        $this->actingAs($this->userWithRole('procurement'));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get('/procurement/approvals')->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(12, $queryCount, "Procurement approval page used {$queryCount} queries.");
    }

    public function test_contract_amount_is_synced_back_to_project(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin);

        $contract = BusinessObject::where('key', 'contract')->firstOrFail();
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
        $customerId = $project->payload['customer_id'];

        $this->post("/objects/{$contract->id}", [
            'payload' => [
                'ctype' => '补充协议',
                'amount' => 140000,
                'customer_id' => $customerId,
                'project_id' => $project->id,
                'status' => '已收到',
            ],
        ])->assertRedirect();

        $project->refresh();
        $this->assertSame(4000000.0, (float) $project->payload['contract_amount']);
        $this->assertStringContainsString('HT-', $project->payload['related_contract_no']);

        $newContract = ObjectRecord::whereRelation('businessObject', 'key', 'contract')
            ->where('payload->ctype', '补充协议')
            ->firstOrFail();
        $this->put("/records/{$newContract->id}", [
            'payload' => [
                ...$newContract->payload,
                'amount' => 200000,
            ],
        ])->assertRedirect();

        $this->assertSame(4060000.0, (float) $project->fresh()->payload['contract_amount']);
    }

    public function test_invoice_amount_is_synced_back_to_project(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $finance = $this->userWithRole('finance');
        $this->actingAs($finance);

        $invoice = BusinessObject::where('key', 'invoice')->firstOrFail();
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
        $customerId = $project->payload['customer_id'];
        $baseAmount = (float) ($project->payload['invoiced_amount'] ?? 0);

        $this->post("/objects/{$invoice->id}", [
            'payload' => [
                'customer_id' => $customerId,
                'project_id' => $project->id,
                'invoice_no' => 'FP-TEST-001',
                'amount' => 200000,
                'invoice_date' => '2026-07-07',
                'status' => '已开票',
            ],
        ])->assertRedirect();

        $project->refresh();
        $this->assertSame($baseAmount + 200000.0, (float) $project->payload['invoiced_amount']);
        $this->assertSame(
            (float) $project->payload['contract_amount'] - $baseAmount - 200000.0,
            (float) $project->payload['uninvoiced_amount'],
        );

        $newInvoice = ObjectRecord::whereRelation('businessObject', 'key', 'invoice')
            ->where('payload->invoice_no', 'FP-TEST-001')
            ->firstOrFail();

        $this->put("/records/{$newInvoice->id}", [
            'payload' => [
                ...$newInvoice->payload,
                'status' => '已作废',
            ],
        ])->assertRedirect();

        $project->refresh();
        $this->assertSame($baseAmount, (float) $project->payload['invoiced_amount']);
    }

    public function test_purchase_fields_match_feishu_purchase_table(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $procurement = $this->userWithRole('procurement');
        $this->actingAs($procurement);

        $purchase = ObjectRecord::whereRelation('businessObject', 'key', 'purchase')->firstOrFail();
        $fields = collect($purchase->businessObject->fields);

        $this->assertSame([
            '日期',
            '采购项目',
            '发起人',
            '材料名称',
            '规格',
            '上报数量',
            '对应吨位',
            '采购日期',
            '供应商名称',
            '最终采购数量',
            '单价',
            '总价',
            '材料是否到货',
            '单日采购状态',
            '预计到货日期',
            '实际到货日期',
            '备注',
            '任务ID',
        ], $fields->pluck('label')->all());
        $this->assertFalse($fields->contains('key', 'completed_by'));
        $this->assertFalse($fields->contains('key', 'acceptance_attachment'));

        $this->put("/records/{$purchase->id}", [
            'payload' => [
                ...$purchase->payload,
                'arrived' => '已到货',
            ],
        ])->assertRedirect();

        $purchase->refresh();
        $this->assertSame(now()->format('Y/m/d'), $purchase->payload['actual_arrival_date']);
    }

    public function test_stock_ledger_is_recalculated_from_stock_movements(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $warehouse = $this->userWithRole('warehouse');
        $this->actingAs($warehouse);

        $material = app(CreateObjectRecord::class)->handle(
            BusinessObject::where('key', 'material')->firstOrFail(),
            ['name' => '自动库存测试材料', 'material_type' => '钢板', 'unit' => '张', 'status' => '启用'],
            $warehouse,
        );

        $inbound = BusinessObject::where('key', 'inbound')->firstOrFail();
        $outbound = BusinessObject::where('key', 'outbound')->firstOrFail();
        $stocktake = BusinessObject::where('key', 'stocktake')->firstOrFail();

        $this->post("/objects/{$inbound->id}", [
            'payload' => ['material_id' => $material->id, 'qty' => 10, 'weight' => 100, 'bin' => 'A-01'],
        ])->assertRedirect();

        $ledger = ObjectRecord::whereRelation('businessObject', 'key', 'stock_ledger')
            ->where('payload->material_id', $material->id)
            ->firstOrFail();
        $this->assertSame(10.0, (float) $ledger->payload['balance']);
        $this->assertSame(10.0, (float) $ledger->payload['in_qty']);

        $this->post("/objects/{$outbound->id}", [
            'payload' => ['material_id' => $material->id, 'qty' => 3, 'team' => '下料班组'],
        ])->assertRedirect();

        $ledger->refresh();
        $this->assertSame(7.0, (float) $ledger->payload['balance']);
        $this->assertSame(3.0, (float) $ledger->payload['out_qty']);

        $this->post("/objects/{$stocktake->id}", [
            'payload' => ['material_id' => $material->id, 'book_qty' => 7, 'real_qty' => 6, 'handle_status' => '已完成'],
        ])->assertRedirect();

        $ledger->refresh();
        $this->assertSame(6.0, (float) $ledger->payload['balance']);
    }

    public function test_role_only_sees_projects_that_have_reached_its_step(): void
    {
        $this->seed(XycPrototypeSeeder::class);

        $projectObject = BusinessObject::where('key', 'project')->firstOrFail();
        ObjectRecord::create([
            'business_object_id' => $projectObject->id,
            'code' => 'PRJ-TEST-EARLY',
            'title' => '还在合同阶段的项目',
            'payload' => [
                'name' => '还在合同阶段的项目',
                'project_no' => 'EARLY-001',
                'stage' => '合同录入',
            ],
        ]);

        $this->actingAs($this->userWithRole('production'));

        $this->get('/')->assertOk();

        $this->get('/objects/project')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ontology/Index')
                ->has('records.data', 1)
                ->where('records.data.0.title', '南通厂房钢结构一期'));

        $this->get('/objects/work_order')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ontology/Index')
                ->has('relationOptions.project_id.items', 1)
                ->where('relationOptions.project_id.items.0.title', '南通厂房钢结构一期'));
    }

    public function test_project_master_write_permissions_are_limited_to_business_finance_and_admin(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();

        foreach (['engineering', 'production', 'procurement', 'warehouse'] as $roleName) {
            $this->actingAs($this->userWithRole($roleName));
            $this->get('/objects/project')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('can.create', false)
                    ->where('can.update', false)
                    ->where('can.delete', false));

            $this->put("/records/{$project->id}", [
                'payload' => [...$project->payload, 'name' => '不允许改名'],
            ])->assertForbidden();
        }

        foreach (['business', 'finance', 'admin'] as $roleName) {
            $this->actingAs($this->userWithRole($roleName));
            $this->get('/objects/project')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('can.update', true));
        }
    }

    public function test_material_master_is_maintained_by_warehouse_and_procurement_is_read_only(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $material = ObjectRecord::whereRelation('businessObject', 'key', 'material')->firstOrFail();

        $this->actingAs($this->userWithRole('procurement'));
        $this->get('/objects/material')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.create', false)
                ->where('can.update', false)
                ->where('can.delete', false));
        $this->put("/records/{$material->id}", [
            'payload' => [...$material->payload, 'name' => '采购不应维护'],
        ])->assertForbidden();

        $this->actingAs($this->userWithRole('warehouse'));
        $this->get('/objects/material')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.update', true));
    }

    public function test_public_purchase_request_waits_for_procurement_approval_before_purchase_daily_created(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $material = ObjectRecord::whereRelation('businessObject', 'key', 'material')->firstOrFail();
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
        $purchaseObject = BusinessObject::where('key', 'purchase')->firstOrFail();
        $purchaseCount = $purchaseObject->records()->count();

        $this->get('/purchase-request')->assertOk();
        $this->post('/purchase-request', [
            'requester' => '生产',
            'material_id' => $material->id,
            'qty' => 6,
            'unit' => '吨',
            'project_id' => $project->id,
            'urgency' => '紧急',
            'reason' => '公开问卷提交',
        ])->assertRedirect('/purchase-request');

        $request = ObjectRecord::whereRelation('businessObject', 'key', 'requisition')
            ->where('payload->reason', '公开问卷提交')
            ->firstOrFail();

        $this->assertNull($request->created_by);
        $this->assertSame('待处理', $request->payload['status']);
        $this->assertSame($purchaseCount, $purchaseObject->records()->count());

        $this->actingAs($this->userWithRole('procurement'));
        $this->post("/requests/{$request->id}/approve")->assertRedirect();

        $request->refresh();
        $purchase = $purchaseObject->records()
            ->get()
            ->first(fn (ObjectRecord $record) => (float) ($record->payload['qty'] ?? 0) === 6.0);

        $this->assertNotNull($purchase);
        $this->assertSame('已转采购', $request->payload['status']);
        $this->assertSame($purchaseCount + 1, $purchaseObject->records()->count());
        $this->assertSame($material->id, $purchase->payload['material_id']);
        $this->assertSame($project->id, $purchase->payload['project_id']);
        $this->assertSame('生产', $purchase->payload['requester']);
        $this->assertSame('6吨', $purchase->payload['reported_qty']);
        $this->assertSame(6.0, (float) $purchase->payload['qty']);
        $this->assertSame('未到货', $purchase->payload['arrived']);
        $this->assertSame('未采购', $purchase->payload['daily_status']);
    }

    public function test_procurement_has_a_dedicated_oa_approval_page(): void
    {
        $this->seed(XycPrototypeSeeder::class);

        $this->actingAs($this->userWithRole('production'));
        $this->get('/procurement/approvals')->assertForbidden();

        $this->actingAs($this->userWithRole('procurement'));
        $this->get('/procurement/approvals')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Requisitions/Approvals')
                ->has('pending', 1)
                ->where('pending.0.display.status', '待处理')
                ->where('nav.2.label', '采购OA审批'));
    }

    public function test_public_material_request_waits_for_warehouse_approval_before_outbound_created(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $material = ObjectRecord::whereRelation('businessObject', 'key', 'material')->firstOrFail();
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
        $outboundObject = BusinessObject::where('key', 'outbound')->firstOrFail();
        $outboundCount = $outboundObject->records()->count();

        $this->get('/material-request')->assertOk();
        $this->post('/material-request', [
            'requester' => '下料班组',
            'material_id' => $material->id,
            'project_id' => $project->id,
            'qty' => 3,
            'unit' => '张',
            'team' => '下料班组',
            'apply_date' => '2026-07-07',
            'reason' => '公开领料测试',
        ])->assertRedirect('/material-request');

        $request = ObjectRecord::whereRelation('businessObject', 'key', 'material_request')
            ->where('payload->reason', '公开领料测试')
            ->firstOrFail();

        $this->assertNull($request->created_by);
        $this->assertSame('待审批', $request->payload['status']);
        $this->assertSame($outboundCount, $outboundObject->records()->count());

        $this->actingAs($this->userWithRole('production'));
        $this->get('/warehouse/material-requests')->assertForbidden();

        $this->actingAs($this->userWithRole('warehouse'));
        $this->get('/warehouse/material-requests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MaterialRequests/Approvals')
                ->has('pending', 1)
                ->where('pending.0.display.status', '待审批'));

        $this->post("/material-requests/{$request->id}/approve")->assertRedirect();

        $request->refresh();
        $this->assertSame('已出库', $request->payload['status']);
        $this->assertNotEmpty($request->payload['outbound_id']);
        $this->assertSame($outboundCount + 1, $outboundObject->records()->count());

        $outbound = ObjectRecord::findOrFail($request->payload['outbound_id']);
        $this->assertSame($material->id, $outbound->payload['material_id']);
        $this->assertSame($project->id, $outbound->payload['project_id']);
        $this->assertSame(3.0, (float) $outbound->payload['qty']);
    }

    public function test_public_team_log_form_creates_team_daily_record(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $workOrder = ObjectRecord::whereRelation('businessObject', 'key', 'work_order')->firstOrFail();

        $this->get('/team-log')->assertOk();
        $this->post('/team-log', [
            'work_order_id' => $workOrder->id,
            'part_name' => '公开班组日报',
            'team' => '班组A',
            'real_qty' => 9,
            'work_date' => '2026-07-07',
        ])->assertRedirect('/team-log');

        $record = ObjectRecord::whereRelation('businessObject', 'key', 'team_log')
            ->where('payload->part_name', '公开班组日报')
            ->firstOrFail();

        $this->assertNull($record->created_by);
        $this->assertSame($workOrder->id, $record->payload['work_order_id']);
        $this->assertSame($workOrder->payload['project_id'], $record->payload['project_id']);
        $this->assertSame($workOrder->payload['drawing_no'], $record->payload['drawing_no']);
        $this->assertSame(9.0, (float) $record->payload['real_qty']);
    }

    public function test_production_task_requires_released_drawing_and_copies_drawing_fields(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
        $drawingObject = BusinessObject::where('key', 'drawing')->firstOrFail();
        $workOrderObject = BusinessObject::where('key', 'work_order')->firstOrFail();
        $releasedDrawing = ObjectRecord::whereRelation('businessObject', 'key', 'drawing')->firstOrFail();

        $this->actingAs($this->userWithRole('production'));
        $this->post("/objects/{$workOrderObject->id}", [
            'payload' => [
                'drawing_id' => $releasedDrawing->id,
                'team' => '班组B',
                'status' => '未完成',
            ],
        ])->assertRedirect();

        $workOrder = ObjectRecord::whereRelation('businessObject', 'key', 'work_order')
            ->where('payload->team', '班组B')
            ->firstOrFail();
        $this->assertSame($releasedDrawing->payload['project_id'], $workOrder->payload['project_id']);
        $this->assertSame($releasedDrawing->payload['drawing_no'], $workOrder->payload['drawing_no']);
        $this->assertSame($releasedDrawing->payload['name'], $workOrder->payload['drawing_name']);

        $unreleasedDrawing = app(CreateObjectRecord::class)->handle($drawingObject, [
            'name' => '未下放图纸',
            'drawing_no' => 'DRW-HOLD',
            'project_id' => $project->id,
            'release_status' => '未下放',
        ]);

        $this->post("/objects/{$workOrderObject->id}", [
            'payload' => [
                'drawing_id' => $unreleasedDrawing->id,
                'team' => '班组C',
                'status' => '未完成',
            ],
        ])->assertSessionHasErrors('payload.drawing_id');
    }

    public function test_drawing_and_shipment_support_attachment_uploads(): void
    {
        Storage::fake('public');
        $this->seed(XycPrototypeSeeder::class);
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
        $drawing = BusinessObject::where('key', 'drawing')->firstOrFail();
        $shipment = BusinessObject::where('key', 'shipment')->firstOrFail();

        $this->actingAs($this->userWithRole('engineering'));
        $this->post("/objects/{$drawing->id}", [
            'payload' => [
                'name' => '带附件图纸',
                'drawing_no' => 'ATT-DRW',
                'project_id' => $project->id,
                'designer' => '技术',
                'release_status' => '已下放',
                'weight' => 10,
                'attachment' => UploadedFile::fake()->create('drawing.pdf', 12, 'application/pdf'),
            ],
        ])->assertRedirect();

        $drawingRecord = ObjectRecord::whereRelation('businessObject', 'key', 'drawing')
            ->where('payload->drawing_no', 'ATT-DRW')
            ->firstOrFail();
        $this->assertStringStartsWith('/storage/attachments/', $drawingRecord->payload['attachment']);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $drawingRecord->payload['attachment']));

        $this->actingAs($this->userWithRole('production'));
        $this->post("/objects/{$shipment->id}", [
            'payload' => [
                'project_id' => $project->id,
                'product_name' => '带附件发货单',
                'qty_ton' => 6,
                'ship_date' => '2026-07-07',
                'sign_status' => '已签收',
                'attachment' => UploadedFile::fake()->create('shipment.jpg', 8, 'image/jpeg'),
            ],
        ])->assertRedirect();

        $shipmentRecord = ObjectRecord::whereRelation('businessObject', 'key', 'shipment')
            ->where('payload->product_name', '带附件发货单')
            ->firstOrFail();
        $this->assertStringStartsWith('/storage/attachments/', $shipmentRecord->payload['attachment']);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $shipmentRecord->payload['attachment']));
    }

    public function test_requesters_only_see_their_own_purchase_requests_in_workspace(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $requisition = BusinessObject::where('key', 'requisition')->firstOrFail();
        $material = ObjectRecord::whereRelation('businessObject', 'key', 'material')->firstOrFail();
        $production = $this->userWithRole('production');
        $warehouse = $this->userWithRole('warehouse');

        ObjectRecord::create([
            'business_object_id' => $requisition->id,
            'code' => 'QG-OWN',
            'title' => '我的采购申请',
            'payload' => ['requester' => '生产', 'material_id' => $material->id, 'qty' => 1, 'unit' => '吨', 'urgency' => '普通', 'status' => '已驳回'],
            'created_by' => $production->id,
        ]);
        ObjectRecord::create([
            'business_object_id' => $requisition->id,
            'code' => 'QG-OTHER',
            'title' => '别人的采购申请',
            'payload' => ['requester' => '库管', 'material_id' => $material->id, 'qty' => 2, 'unit' => '吨', 'urgency' => '普通', 'status' => '已驳回'],
            'created_by' => $warehouse->id,
        ]);

        $this->actingAs($production);
        $this->get('/objects/requisition')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('records.data', 1)
                ->where('records.data.0.code', 'QG-OWN')
                ->where('can.update', false));
    }

    public function test_project_page_exposes_flat_fields_without_relation_chain(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->artisan('xyc:admin', [
            'email' => 'admin@example.com',
            '--password' => 'password123',
        ]);

        $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'password123',
        ]);

        $this->get('/objects/project')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ontology/Index')
                ->has('nav.4.children', 3)
                ->has('relationOptions.customer_id.items', 1)
                ->where('selectedRecordId', null)
                ->has('currentObject.fields', 23)
                ->where('currentObject.fields.0.key', 'name')
                ->where('currentObject.fields.0.label', '项目名称')
                ->where('currentObject.fields.1.key', 'customer_id')
                ->where('currentObject.fields.1.label', '客户名称')
                ->where('currentObject.fields.2.key', 'project_no')
                ->where('currentObject.fields.2.label', '项目编号')
                ->missing('relationChain'));
    }

    public function test_removed_bin_card_object_is_not_synced(): void
    {
        $this->seed(XycPrototypeSeeder::class);

        $this->assertFalse(BusinessObject::where('key', 'bin_card')->exists());
        $this->assertFalse(BusinessObject::where('key', 'supplier')->exists());
        $this->assertTrue(BusinessObject::where('key', 'scrap_ledger')->exists());
        $this->assertFalse(collect(BusinessObject::where('key', 'purchase')->firstOrFail()->fields)->contains('key', 'supplier_id'));
        $this->assertFalse(collect(BusinessObject::where('key', 'inbound')->firstOrFail()->fields)->contains('key', 'supplier_id'));
    }

    public function test_record_codes_use_next_available_suffix(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $material = BusinessObject::where('key', 'material')->firstOrFail();
        $date = now()->format('Ymd');

        ObjectRecord::create([
            'business_object_id' => $material->id,
            'code' => "MAT-{$date}-047",
            'title' => '已有高位编号',
            'payload' => ['name' => '已有高位编号'],
        ]);

        $this->assertSame("MAT-{$date}-048", app(CreateObjectRecord::class)->nextCode($material));
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::create([
            'name' => Role::where('name', $roleName)->firstOrFail()->label,
            'email' => "{$roleName}@example.com",
            'password' => Hash::make('password123'),
        ]);

        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
