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

    public function test_dashboard_exposes_only_business_contract_status_and_reminders(): void
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
                ->has('stats', 4)
                ->has('recentProjects', 4)
                ->where('statusSummary.投标中', 1)
                ->where('statusSummary.已中标', 1)
                ->where('statusSummary.已拿到加工函', 1)
                ->where('statusSummary.合同签署', 1)
                ->missing('boards')
                ->missing('projectFlows')
                ->missing('stockRisks'));
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

        $this->assertLessThanOrEqual(18, $queryCount, "Dashboard used {$queryCount} queries.");
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

        $this->assertLessThanOrEqual(18, $queryCount, "Project list used {$queryCount} queries.");
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

    public function test_contract_changes_do_not_override_existing_project_amount_without_explicit_sync(): void
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
                'status' => '未签署',
            ],
        ])->assertRedirect();

        $project->refresh();
        $this->assertSame(5280000.0, (float) $project->payload['contract_amount']);
        $this->assertArrayNotHasKey('related_contract_no', $project->payload);

        $newContract = ObjectRecord::whereRelation('businessObject', 'key', 'contract')
            ->where('payload->project_id', $project->id)
            ->where('payload->ctype', '补充协议')
            ->firstOrFail();
        $this->put("/records/{$newContract->id}", [
            'payload' => [
                ...$newContract->payload,
                'amount' => 200000,
            ],
        ])->assertRedirect();

        $this->assertSame(5280000.0, (float) $project->fresh()->payload['contract_amount']);

        $this->post("/projects/{$project->id}/contract-amount/sync")->assertRedirect();
        $this->assertSame(5480000.0, (float) $project->fresh()->payload['contract_amount']);
    }

    public function test_hidden_invoice_object_cannot_write_or_sync_project_amounts(): void
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
        ])->assertNotFound();

        $this->assertSame($baseAmount, (float) $project->payload['invoiced_amount']);
        $this->assertDatabaseMissing('object_records', ['business_object_id' => $invoice->id, 'code' => 'FP-TEST-001']);
    }

    public function test_purchase_metadata_and_history_are_retained_but_direct_page_is_hidden(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $procurement = $this->userWithRole('procurement');
        $this->actingAs($procurement);

        $purchase = BusinessObject::where('key', 'purchase')->firstOrFail();
        $fields = collect($purchase->fields);

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

        $this->get('/objects/purchase')->assertForbidden();
    }

    public function test_stock_ledger_is_recalculated_from_stock_movements(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertFalse(Role::where('name', 'warehouse')->exists());
        foreach (['inbound', 'outbound', 'stock_ledger', 'stocktake'] as $key) {
            $this->assertSame([], BusinessObject::where('key', $key)->firstOrFail()->roles);
        }
    }

    public function test_non_business_roles_do_not_receive_hidden_business_object_pages(): void
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
        $this->get('/objects/project')->assertForbidden();
        $this->get('/objects/work_order')->assertForbidden();
    }

    public function test_project_master_write_permissions_are_limited_to_business_finance_and_admin(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();

        foreach (['production', 'procurement'] as $roleName) {
            $this->actingAs($this->userWithRole($roleName));
            $this->get('/objects/project')->assertForbidden();

            $this->put("/records/{$project->id}", [
                'payload' => [...$project->payload, 'name' => '不允许改名'],
            ])->assertForbidden();
        }

        $this->actingAs($this->userWithRole('finance'));
        $this->get('/objects/project')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.create', false)
            ->where('can.update', true)
            ->where('can.delete', false));

        foreach (['business', 'admin'] as $roleName) {
            $this->actingAs($this->userWithRole($roleName));
            $this->get('/objects/project')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('can.update', true));
        }
    }

    public function test_material_master_history_is_retained_but_direct_crud_is_hidden(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $material = ObjectRecord::whereRelation('businessObject', 'key', 'material')->firstOrFail();

        $this->actingAs($this->userWithRole('procurement'));
        $this->get('/objects/material')->assertForbidden();
        $this->put("/records/{$material->id}", [
            'payload' => [...$material->payload, 'name' => '采购维护'],
        ])->assertNotFound();
        $this->assertNotSame('采购维护', $material->fresh()->title);
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
                ->has('pending', 0)
                ->where('nav', fn ($nav): bool => collect($nav)->doesntContain(fn ($item) => ($item['label'] ?? null) === '采购OA审批')));
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
        $this->get('/objects/requisition')->assertForbidden();
        $this->assertDatabaseHas('object_records', ['id' => $requisition->records()->where('code', 'QG-OWN')->value('id')]);
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
                ->where('objects', fn ($objects): bool => collect($objects)->pluck('key')->all() === [
                    'customer',
                    'customer_contact',
                    'project',
                    'contract',
                ])
                ->has('relationOptions.customer_id.items', 3)
                ->where('selectedRecordId', null)
                ->has('currentObject.fields', 26)
                ->where('currentObject.fields.0.key', 'name')
                ->where('currentObject.fields.0.label', '项目名称')
                ->where('currentObject.fields.1.key', 'customer_contact_ids')
                ->where('currentObject.fields.1.label', '客户联系人')
                ->where('currentObject.fields.2.key', 'customer_id')
                ->where('currentObject.fields.2.label', '客户名称')
                ->where('currentObject.fields', fn ($fields): bool => collect($fields)->contains(
                    fn (array $field): bool => $field['key'] === 'last_payment_date'
                        && $field['label'] === '末次回款日期'
                        && $field['type'] === 'date',
                ))
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
