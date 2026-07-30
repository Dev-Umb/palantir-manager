<?php

namespace Tests\Feature;

use App\Actions\CreateObjectRecord;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        $this->seed(XycPrototypeSeeder::class);
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
        $this->seed(XycPrototypeSeeder::class);
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
                ->has('boards', 3)
                ->where('boards.0.title', '经营大盘')
                ->where('boards.1.title', '采购大盘')
                ->where('boards.2.title', '财务大盘')
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
        $this->assertSame($baseAmount, (float) $project->payload['invoiced_amount']);

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
            '材质/型号',
            '规格',
            '上报数量',
            '采购日期',
            '供应商名称',
            '最终采购数量',
            '重量（吨）',
            '单位',
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
        $this->assertArrayNotHasKey('actual_arrival_date', $purchase->payload);
    }

    public function test_stock_ledger_is_recalculated_from_stock_movements(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertFalse(Role::where('name', 'warehouse')->exists());
        foreach (['inbound', 'outbound', 'stock_ledger', 'stocktake'] as $key) {
            $this->assertSame([], BusinessObject::where('key', $key)->firstOrFail()->roles);
        }
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

        foreach (['production', 'procurement', 'finance'] as $roleName) {
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

        foreach (['business', 'admin'] as $roleName) {
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
                ->where('can.create', true)
                ->where('can.update', true)
                ->where('can.delete', true));
        $this->put("/records/{$material->id}", [
            'payload' => [...$material->payload, 'name' => '采购维护'],
        ])->assertRedirect();
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
        $purchase = $purchaseObject->records()->get()->first(
            fn (ObjectRecord $record) => (float) ($record->payload['items'][0]['qty'] ?? 0) === 6.0,
        );

        $this->assertNotNull($purchase);
        $this->assertSame('已转采购', $request->payload['status']);
        $this->assertSame($purchaseCount + 1, $purchaseObject->records()->count());
        $this->assertSame($material->id, $purchase->payload['items'][0]['material_id']);
        $this->assertSame('', $purchase->payload['project_id']);
        $this->assertSame('生产', $purchase->payload['requester']);
        $this->assertSame('6吨', $purchase->payload['items'][0]['reported_qty']);
        $this->assertSame(6.0, (float) $purchase->payload['items'][0]['qty']);
        $this->assertSame('未到货', $purchase->payload['items'][0]['arrived']);
        $this->assertSame('未采购', $purchase->payload['items'][0]['daily_status']);
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
                ->where('nav.3.label', '采购OA审批'));
    }

    public function test_public_material_request_waits_for_warehouse_approval_before_outbound_created(): void
    {
        $this->get('/material-request')->assertNotFound();
        $this->post('/material-request')->assertNotFound();
    }

    public function test_public_team_log_form_creates_team_daily_record(): void
    {
        $this->get('/team-log/public')->assertForbidden();
        $this->post('/team-log/public')->assertForbidden();
    }

    public function test_production_task_requires_released_drawing_and_copies_drawing_fields(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $workOrderObject = BusinessObject::where('key', 'work_order')->firstOrFail();
        $drawing = collect($workOrderObject->fields)->firstWhere('key', 'drawing_id');
        $this->assertSame('drawing', $drawing['target']);
        $this->assertTrue($drawing['required']);
    }

    public function test_drawing_and_shipment_support_attachment_uploads(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $drawing = BusinessObject::where('key', 'drawing')->firstOrFail();
        $shipment = BusinessObject::where('key', 'shipment')->firstOrFail();
        $this->assertSame('file', collect($drawing->fields)->firstWhere('key', 'attachment')['type']);
        $this->assertSame('file', collect($shipment->fields)->firstWhere('key', 'attachment')['type']);
    }

    public function test_requesters_only_see_their_own_purchase_requests_in_workspace(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $requisition = BusinessObject::where('key', 'requisition')->firstOrFail();
        $material = ObjectRecord::whereRelation('businessObject', 'key', 'material')->firstOrFail();
        $production = $this->userWithRole('production');
        $warehouse = $this->userWithRole('production_manager');

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
                ->has('nav.5.children', 4)
                ->has('relationOptions.customer_id.items', 1)
                ->where('selectedRecordId', null)
                ->has('currentObject.fields', 27)
                ->where('currentObject.fields.0.key', 'name')
                ->where('currentObject.fields.0.label', '项目名称')
                ->where('currentObject.fields.1.key', 'customer_contact_ids')
                ->where('currentObject.fields.1.label', '客户联系人')
                ->where('currentObject.fields.2.key', 'customer_id')
                ->where('currentObject.fields.2.label', '客户名称')
                ->missing('relationChain'));
    }

    public function test_removed_bin_card_object_is_not_synced(): void
    {
        $this->seed(XycPrototypeSeeder::class);

        $this->assertFalse(BusinessObject::where('key', 'bin_card')->exists());
        $this->assertFalse(BusinessObject::where('key', 'supplier')->exists());
        $this->assertFalse(BusinessObject::where('key', 'scrap_ledger')->exists());
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
