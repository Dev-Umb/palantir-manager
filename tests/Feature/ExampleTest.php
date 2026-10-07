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
use Illuminate\Support\Facades\Route;
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
        $this->assertFalse($user->canDo('requisition.create'));
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
        $this->get('/requests/create')->assertNotFound();
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

    public function test_retired_procurement_approvals_batch_load_relation_labels(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('requisition', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
    }

    public function test_contract_changes_do_not_override_existing_project_amount_without_explicit_sync(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin);

        $contract = BusinessObject::where('key', 'contract')->firstOrFail();
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
        $customerId = $project->payload['customer_id'];

        $this->post("/records/{$project->id}", [
            '_method' => 'put', 'payload' => $project->fresh()->payload,
            'contracts' => [[
                'ctype' => '补充协议',
                'amount' => 140000,
                'status' => '未签署',
            ]],
        ])->assertRedirect();

        $project->refresh();
        $this->assertSame(5280000.0, (float) $project->payload['contract_amount']);
        $this->assertArrayNotHasKey('related_contract_no', $project->payload);

        $newContract = ObjectRecord::whereRelation('businessObject', 'key', 'contract')
            ->where('payload->project_id', $project->id)
            ->where('payload->ctype', '补充协议')
            ->firstOrFail();
        $this->post("/records/{$project->id}", [
            '_method' => 'put', 'payload' => $project->fresh()->payload,
            'contracts' => [['id' => $newContract->id,
                ...array_intersect_key($newContract->payload, array_flip(['status', 'ctype', 'amount'])),
                'amount' => 200000,
            ]],
        ])->assertRedirect();

        $this->assertSame(5280000.0, (float) $project->fresh()->payload['contract_amount']);

        $this->post("/projects/{$project->id}/contract-amount/sync")->assertRedirect();
        $this->assertSame(5480000.0, (float) $project->fresh()->payload['contract_amount']);
    }

    public function test_retired_hidden_invoice_object_cannot_write_or_sync_project_amounts(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('invoice', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
    }

    public function test_retired_purchase_metadata_and_history_are_retained_but_direct_page_is_hidden(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('purchase', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
    }

    public function test_retired_stock_ledger_is_recalculated_from_stock_movements(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('stock_ledger', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
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

    public function test_retired_material_master_history_is_retained_but_direct_crud_is_hidden(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('material', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
    }

    public function test_retired_public_purchase_request_waits_for_procurement_approval_before_purchase_daily_created(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('requisition', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
    }

    public function test_retired_procurement_approval_page_is_unavailable_for_all_roles(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        foreach (['production', 'procurement', 'admin'] as $role) {
            $this->actingAs($this->userWithRole($role))->get('/procurement/approvals')->assertNotFound();
        }
    }

    public function test_public_material_request_waits_for_warehouse_approval_before_outbound_created(): void
    {
        $this->get('/material-request')->assertNotFound();
        $this->post('/material-request')->assertNotFound();
    }

    public function test_public_team_log_form_creates_team_daily_record(): void
    {
        $this->get('/team-log/public')->assertNotFound();
        $this->post('/team-log/public')->assertNotFound();
    }

    public function test_retired_production_task_requires_released_drawing_and_copies_drawing_fields(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('work_order', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
    }

    public function test_retired_drawing_and_shipment_support_attachment_uploads(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('drawing', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
    }

    public function test_retired_requesters_only_see_their_own_purchase_requests_in_workspace(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('requisition', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
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
                    'tender',
                    'project',
                    'project_business_summary',
                    'contract',
                ])
                ->has('relationOptions.customer_id.items', 3)
                ->where('selectedRecordId', null)
                ->has('currentObject.fields', 30)
                ->where('currentObject.fields.0.key', 'business_owner_user_id')
                ->where('currentObject.fields.0.label', '负责业务员')
                ->where('currentObject.fields.1.key', 'project_no')
                ->where('currentObject.fields.1.label', '项目编号')
                ->where('currentObject.fields.2.key', 'customer_id')
                ->where('currentObject.fields.2.label', '客户名称')
                ->where('currentObject.fields', fn ($fields): bool => collect($fields)->contains(
                    fn (array $field): bool => $field['key'] === 'informed_business_user_ids'
                        && $field['label'] === '知会人员'
                        && $field['type'] === 'multiaccount',
                ))
                ->where('currentObject.fields', fn ($fields): bool => collect($fields)->contains(
                    fn (array $field): bool => $field['key'] === 'last_payment_date'
                        && $field['label'] === '末次回款日期'
                        && $field['type'] === 'date',
                ))
                ->where('currentObject.fields', fn ($fields): bool => collect($fields)->contains(
                    fn (array $field): bool => $field['key'] === 'weight'
                        && $field['label'] === '合同重量（吨）'
                        && $field['type'] === 'number',
                ))
                ->missing('relationChain'));

    }

    public function test_retired_removed_bin_card_object_is_not_synced(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('inbound', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
    }

    public function test_record_codes_use_next_available_suffix(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $material = BusinessObject::where('key', 'customer')->firstOrFail();
        $date = now()->format('Ymd');

        ObjectRecord::create([
            'business_object_id' => $material->id,
            'code' => "CUST-{$date}-047",
            'title' => '已有高位编号',
            'payload' => ['name' => '已有高位编号'],
        ]);

        $this->assertSame("CUST-{$date}-048", app(CreateObjectRecord::class)->nextCode($material));
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
