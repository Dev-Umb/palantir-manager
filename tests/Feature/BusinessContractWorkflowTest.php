<?php

namespace Tests\Feature;

use App\Actions\SyncProjectNotifications;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\ProjectNotification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BusinessContractWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('xyc:sync-metadata')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_visible_business_tables_preserve_customer_contact_management_metadata_and_scope_projects(): void
    {
        $businessA = $this->userWithRole('business', '业务员甲');
        $businessB = $this->userWithRole('business', '业务员乙');
        $projectA = $this->project($businessA, '甲方项目');
        $projectB = $this->project($businessB, '乙方项目');

        $this->actingAs($businessA)
            ->get('/objects/project')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ontology/Index')
                ->has('objects', 5)
                ->where('objects', fn ($objects): bool => collect($objects)->pluck('key')->all() === [
                    'customer',
                    'tender',
                    'project',
                    'project_business_summary',
                    'contract',
                ])
                ->where('contactObject.key', 'customer_contact')
                ->has('records.data', 1)
                ->where('records.data.0.id', $projectA->id)
                ->where('currentObject.fields', fn ($fields): bool => collect($fields)
                    ->firstWhere('key', 'contract_amount')['readonly'] === true
                    && collect($fields)->firstWhere('key', 'signed_weight')['readonly'] === true
                    && collect($fields)->firstWhere('key', 'last_payment_date')['readonly'] === true));

        $this->actingAs($businessA)->get("/objects/project?record={$projectB->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selectedRecord', null));
        $this->actingAs($this->userWithRole('admin', '管理员'))
            ->get('/objects/drawing')
            ->assertForbidden();

        $this->assertDatabaseCount('object_records', 4);
    }

    public function test_project_owner_and_admin_manage_valid_informed_business_users(): void
    {
        $owner = $this->userWithRole('business', '负责业务员');
        $informed = $this->userWithRole('business', '知会业务员');
        $admin = $this->userWithRole('admin', '管理员');
        $nonBusiness = $this->userWithRole('finance', '财务');
        $project = $this->project($owner);

        $this->actingAs($owner)->put("/records/{$project->id}", [
            'payload' => [...$project->payload, 'informed_business_user_ids' => [(string) $informed->id, (string) $informed->id]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([(string) $informed->id], $project->fresh()->payload['informed_business_user_ids']);

        $this->actingAs($admin)->put("/records/{$project->id}", [
            'payload' => [...$project->fresh()->payload, 'informed_business_user_ids' => [(string) $owner->id, (string) $informed->id]],
        ])->assertRedirect();
        $this->assertSame([(string) $owner->id, (string) $informed->id], $project->fresh()->payload['informed_business_user_ids']);

        $this->actingAs($admin)->put("/records/{$project->id}", [
            'payload' => [...$project->fresh()->payload, 'informed_business_user_ids' => [(string) $nonBusiness->id]],
        ])->assertSessionHasErrors('payload.informed_business_user_ids');
        $this->assertSame([(string) $owner->id, (string) $informed->id], $project->fresh()->payload['informed_business_user_ids']);

        $this->actingAs($nonBusiness)->put("/records/{$project->id}", [
            'payload' => [...$project->fresh()->payload, 'contract_amount' => 120000, 'informed_business_user_ids' => []],
        ])->assertRedirect();
        $this->assertSame([(string) $owner->id, (string) $informed->id], $project->fresh()->payload['informed_business_user_ids']);

        $this->actingAs($owner)->get('/objects/project')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentObject.fields', fn ($fields): bool => ! (collect($fields)
                    ->firstWhere('key', 'informed_business_user_ids')['readonly'] ?? false))
                ->where('relationOptions.informed_business_user_ids.items', fn ($items): bool => collect($items)
                    ->pluck('id')
                    ->contains((string) $informed->id)));
    }

    public function test_informed_business_user_sees_only_the_project_as_read_only_with_an_indicator(): void
    {
        $owner = $this->userWithRole('business', '负责业务员');
        $informed = $this->userWithRole('business', '知会业务员');
        $unrelated = $this->userWithRole('business', '无关业务员');
        $finance = $this->userWithRole('finance', '财务');
        $project = $this->project($owner, overrides: [
            'informed_business_user_ids' => [(string) $informed->id],
        ]);
        ObjectRecord::create([
            'business_object_id' => $this->object('contract')->id,
            'code' => 'HT-'.str()->uuid(),
            'title' => 'HT-知会隔离',
            'created_by' => $owner->id,
            'payload' => [
                'project_id' => $project->id,
                'customer_id' => $project->payload['customer_id'],
                'status' => '未签署',
                'amount' => 100000,
            ],
        ]);

        $this->actingAs($informed)->get('/objects/project')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('records.data', 1)
                ->where('records.data.0.id', $project->id)
                ->where('records.data.0.can_update', false)
                ->where('records.data.0.is_informed_project', true)
                ->where('records.data.0.display.informed_business_user_ids.0', '知会业务员'));

        $this->actingAs($informed)->put("/records/{$project->id}", [
            'payload' => [...$project->payload, 'remark' => '越权修改'],
        ])->assertForbidden();
        $this->assertSame('', $project->fresh()->payload['remark']);

        $this->actingAs($informed)->get('/objects/contract')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('records.data', 0));
        $this->actingAs($unrelated)->get('/objects/project')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('records.data', 0));

        $this->actingAs($owner)->get('/objects/project')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.data.0.can_update', true)
                ->where('records.data.0.is_informed_project', false));
        $this->actingAs($finance)->get('/objects/project')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.data.0.can_update', true)
                ->where('records.data.0.is_informed_project', false));
    }

    public function test_finance_edits_all_project_financial_fields_while_business_cannot_tamper_with_them(): void
    {
        Carbon::setTestNow('2026-08-01 10:30:00');
        $business = $this->userWithRole('business', '业务员');
        $finance = $this->userWithRole('finance', '财务');
        $project = $this->project($business, overrides: ['signed_weight' => 18.5]);

        $this->actingAs($finance)->get('/objects/project')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentObject.fields', fn ($fields): bool => ! (collect($fields)
                    ->firstWhere('key', 'signed_weight')['readonly'] ?? false)
                    && ! (collect($fields)->firstWhere('key', 'last_payment_date')['readonly'] ?? false)
                    && collect($fields)->firstWhere('key', 'last_payment_date')['type'] === 'date'));

        $financePayload = [
            ...$project->payload,
            'signed_weight' => 36.75,
            'contract_amount' => 2800000,
            'occurred_amount' => 280000,
            'paid_amount' => 10000,
            'last_payment_date' => '2026-02-05',
            'unpaid_amount' => 270000,
            'reconciled_amount' => 200000,
            'invoiced_amount' => 90004,
            'uninvoiced_amount' => 189996,
            'payment_progress' => 3.57,
            'payment_status' => '部分回款',
        ];
        $this->actingAs($finance)->put("/records/{$project->id}", ['payload' => $financePayload])->assertRedirect();

        $fresh = $project->fresh();
        $this->assertSame(36.75, (float) $fresh->payload['signed_weight']);
        $this->assertSame(2800000.0, (float) $fresh->payload['contract_amount']);
        $this->assertSame(3.57, (float) $fresh->payload['payment_progress']);
        $this->assertSame('2026-02-05', $fresh->payload['last_payment_date']);
        $this->assertSame('manual', $fresh->payload['contract_amount_source']);
        $this->assertSame(now()->toISOString(), $fresh->payload['payment_reminder_anchor_at']);

        $tampered = [
            ...$fresh->payload,
            'remark' => '业务正常更新',
            'paid_amount' => 999999,
            'last_payment_date' => '2026-07-31',
            'signed_weight' => 999999,
        ];
        $this->actingAs($business)->put("/records/{$project->id}", ['payload' => $tampered])->assertRedirect();
        $fresh = $project->fresh();
        $this->assertSame('业务正常更新', $fresh->payload['remark']);
        $this->assertSame(10000.0, (float) $fresh->payload['paid_amount']);
        $this->assertSame('2026-02-05', $fresh->payload['last_payment_date']);
        $this->assertSame(36.75, (float) $fresh->payload['signed_weight']);

        $admin = $this->userWithRole('admin', '管理员');
        $this->actingAs($admin)->put("/records/{$project->id}", [
            'payload' => [...$fresh->payload, 'signed_weight' => 48.25],
        ])->assertRedirect();
        $this->assertSame(48.25, (float) $project->fresh()->payload['signed_weight']);
    }

    public function test_contract_evidence_is_required_and_multiple_contracts_aggregate_to_partial_then_signed(): void
    {
        $admin = $this->userWithRole('admin', '管理员');
        $business = $this->userWithRole('business', '业务员');
        $project = $this->project($business);
        $contractObject = $this->object('contract');

        $this->actingAs($admin)->post("/objects/{$contractObject->id}", [
            'payload' => $this->contractPayload($project, '已有加工函', 100000),
        ])->assertSessionHasErrors('payload.processing_letter_attachments');

        $this->actingAs($admin)->post("/objects/{$contractObject->id}", [
            'payload' => [
                ...$this->contractPayload($project, '已有加工函', 100000),
                'processing_letter_attachments' => [UploadedFile::fake()->create('加工函.pdf', 100, 'application/pdf')],
            ],
        ])->assertRedirect();

        $this->actingAs($admin)->post("/objects/{$contractObject->id}", [
            'payload' => [
                ...$this->contractPayload($project, '已签署', 200000),
                'contract_attachments' => [UploadedFile::fake()->create('合同.pdf', 100, 'application/pdf')],
            ],
        ])->assertRedirect();

        $contracts = $contractObject->records()->where('payload->project_id', $project->id)->get();
        $this->assertCount(2, $contracts);
        $this->assertSame('部分签署', $project->fresh()->payload['contract_status']);
        $this->assertSame('已拿到加工函', $project->fresh()->payload['overall_status']);
        $this->assertArrayNotHasKey('related_contract_no', $project->fresh()->payload);

        $processingContract = $contracts->firstWhere('payload.status', '已有加工函');
        $this->actingAs($admin)->put("/records/{$processingContract->id}", [
            'payload' => [
                ...$processingContract->payload,
                'status' => '已签署',
                'contract_attachments' => [UploadedFile::fake()->create('补签合同.pdf', 100, 'application/pdf')],
                'processing_letter_attachments' => [],
                'statement_attachments' => [],
            ],
        ])->assertRedirect();

        $this->assertSame('已签署', $project->fresh()->payload['contract_status']);
        $this->assertSame('合同签署', $project->fresh()->payload['overall_status']);
        $this->assertNotEmpty($processingContract->fresh()->payload['processing_letter_attachments']);
        $this->assertNotEmpty($processingContract->fresh()->payload['contract_attachments']);
    }

    public function test_contract_amount_initializes_once_manual_value_is_protected_and_explicit_sync_overwrites(): void
    {
        $admin = $this->userWithRole('admin', '管理员');
        $finance = $this->userWithRole('finance', '财务');
        $business = $this->userWithRole('business', '业务员');
        $project = $this->project($business);
        $contractObject = $this->object('contract');

        $this->actingAs($admin)->post("/objects/{$contractObject->id}", [
            'payload' => $this->contractPayload($project, '未签署', 100000),
        ])->assertRedirect();
        $this->assertSame(100000.0, (float) $project->fresh()->payload['contract_amount']);

        $manual = [...$project->fresh()->payload, 'contract_amount' => 180000];
        $this->actingAs($finance)->put("/records/{$project->id}", ['payload' => $manual])->assertRedirect();

        $this->actingAs($admin)->post("/objects/{$contractObject->id}", [
            'payload' => $this->contractPayload($project, '未签署', 200000),
        ])->assertRedirect();
        $this->assertSame(180000.0, (float) $project->fresh()->payload['contract_amount']);

        $this->actingAs($finance)->post("/projects/{$project->id}/contract-amount/sync")->assertRedirect();
        $fresh = $project->fresh();
        $this->assertSame(300000.0, (float) $fresh->payload['contract_amount']);
        $this->assertSame('contract_sync', $fresh->payload['contract_amount_source']);
        $this->assertSame($finance->id, $fresh->payload['contract_amount_synced_by']);
    }

    public function test_reminder_cycles_are_idempotent_repeat_and_increment_collection_count_once_per_project(): void
    {
        Carbon::setTestNow('2026-08-01 09:00:00');
        $admin = $this->userWithRole('admin', '管理员');
        $finance = $this->userWithRole('finance', '财务');
        $business = $this->userWithRole('business', '业务员');
        $informed = $this->userWithRole('business', '知会业务员');
        $bid = $this->project($business, '投标提醒项目', [
            'overall_status' => '投标中',
            'overall_status_changed_at' => now()->subDays(15)->toISOString(),
            'informed_business_user_ids' => [(string) $informed->id],
        ]);
        $payment = $this->project($business, '回款提醒项目', [
            'overall_status' => '已拿到加工函',
            'contract_status' => '已有加工函',
            'processing_letter_at' => now()->subMonthNoOverflow()->toISOString(),
            'payment_reminder_anchor_at' => now()->subMonthNoOverflow()->toISOString(),
            'payment_status' => '部分回款',
        ]);

        $sync = app(SyncProjectNotifications::class);
        $first = $sync->handle();
        $this->assertSame(2, $first['triggered']);
        $this->assertSame(1, $payment->fresh()->payload['collection_count']);
        $this->assertSame(5, ProjectNotification::query()->count());

        $second = $sync->handle();
        $this->assertSame(0, $second['triggered']);
        $this->assertSame(1, $payment->fresh()->payload['collection_count']);

        Carbon::setTestNow(now()->addDays(15));
        $third = $sync->handleProjects([$bid->id]);
        $this->assertSame(1, $third['triggered']);
        $this->assertSame(2, ProjectNotification::query()
            ->where('project_id', $bid->id)
            ->where('user_id', $business->id)
            ->value('occurrences'));

        $this->assertTrue(ProjectNotification::query()->where('user_id', $admin->id)->exists());
        $this->assertTrue(ProjectNotification::query()->where('user_id', $finance->id)->where('type', ProjectNotification::TYPE_PAYMENT)->exists());
        $this->assertFalse(ProjectNotification::query()->where('user_id', $informed->id)->exists());
    }

    public function test_project_filters_support_top_level_and_or_logic(): void
    {
        $admin = $this->userWithRole('admin', '管理员');
        $owner = $this->userWithRole('business', '业务员');
        $this->project($owner, '风电项目', ['overall_status' => '已中标', 'contract_amount' => 120000, 'last_payment_date' => '2026-01-31']);
        $this->project($owner, '仓储项目', ['overall_status' => '投标中', 'contract_amount' => 500000, 'last_payment_date' => '2026-02-05']);
        $this->project($owner, '车间项目', ['overall_status' => '投标中', 'contract_amount' => 80000]);

        $query = http_build_query([
            'filter_logic' => 'or',
            'filters' => [
                ['field' => 'overall_status', 'operator' => 'equals', 'value' => '已中标'],
                ['field' => 'contract_amount', 'operator' => 'greater_than', 'value' => '200000'],
            ],
        ]);
        $this->actingAs($admin)->get('/objects/project?'.$query)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('records.data', 2)
                ->where('records.data', fn ($records): bool => collect($records)->pluck('title')->sort()->values()->all() === ['仓储项目', '风电项目']));

        $andQuery = str_replace('filter_logic=or', 'filter_logic=and', $query);
        $this->actingAs($admin)->get('/objects/project?'.$andQuery)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('records.data', 0));

        $dateQuery = http_build_query([
            'sort' => 'last_payment_date',
            'direction' => 'desc',
            'filters' => [
                ['field' => 'last_payment_date', 'operator' => 'after', 'value' => '2026-02-01'],
            ],
        ]);
        $this->actingAs($admin)->get('/objects/project?'.$dateQuery)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('records.data', 1)
                ->where('records.data.0.title', '仓储项目')
                ->where('records.data.0.payload.last_payment_date', '2026-02-05'));
    }

    public function test_business_can_see_and_maintain_customer_and_contact_tables_and_embedded_api(): void
    {
        $business = $this->userWithRole('business', '业务员');

        $customerResponse = $this->actingAs($business)->postJson('/project-customers', [
            'name' => '江苏海岳装备有限公司（测试）',
            'address' => '南通市开发区测试路1号',
            'level' => 'A',
            'remark' => '项目内创建',
        ])->assertCreated();
        $customerId = $customerResponse->json('customer.id');

        $contactResponse = $this->actingAs($business)->postJson("/project-customers/{$customerId}/contacts", [
            'name' => '顾经理',
            'phone' => '13800000000',
        ])->assertCreated();
        $contactId = $contactResponse->json('contact.id');

        $this->actingAs($business)->putJson("/project-customers/{$customerId}", [
            'name' => '江苏海岳新能源装备有限公司（测试）',
            'address' => '南通市开发区测试路2号',
            'level' => 'A',
            'remark' => '项目内修改',
        ])->assertOk();
        $this->actingAs($business)->putJson("/project-customers/{$customerId}/contacts/{$contactId}", [
            'name' => '顾总监',
            'phone' => '13900000000',
        ])->assertOk();

        $this->actingAs($business)->get('/objects/customer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentObject.key', 'customer')
                ->where('can.create', true)
                ->where('can.update', true));
        $this->actingAs($business)->get('/objects/customer_contact')->assertForbidden();
        $this->actingAs($business)->get('/objects/customer_contact/export.csv')->assertNotFound();
        $this->assertSame('江苏海岳新能源装备有限公司（测试）', ObjectRecord::findOrFail($customerId)->title);
        $this->assertSame('顾总监', ObjectRecord::findOrFail($contactId)->title);
        $this->assertSame($customerId, ObjectRecord::findOrFail($contactId)->payload['customer_id']);
    }

    public function test_project_customer_api_atomically_creates_customer_with_contact(): void
    {
        $business = $this->userWithRole('business', '业务员');

        $response = $this->actingAs($business)->postJson('/project-customers', [
            'name' => '组合保存客户',
            'address' => '组合保存地址',
            'level' => 'B',
            'remark' => '组合保存备注',
            'contact' => [
                'name' => '组合联系人',
                'phone' => '13800138000',
            ],
        ])->assertCreated();

        $customer = ObjectRecord::findOrFail($response->json('customer.id'));
        $contact = ObjectRecord::findOrFail($response->json('contact.id'));

        $this->assertSame('组合保存客户', $customer->title);
        $this->assertSame('组合联系人', $contact->title);
        $this->assertSame($customer->id, $contact->payload['customer_id']);
        $this->assertSame('13800138000', $contact->payload['phone']);
    }

    public function test_project_customer_api_atomically_updates_customer_with_its_contact(): void
    {
        $business = $this->userWithRole('business', '业务员');
        $created = $this->actingAs($business)->postJson('/project-customers', [
            'name' => '更新前客户',
            'contact' => ['name' => '更新前联系人', 'phone' => '13000000000'],
        ])->assertCreated();
        $customerId = $created->json('customer.id');
        $contactId = $created->json('contact.id');

        $this->actingAs($business)->putJson("/project-customers/{$customerId}", [
            'name' => '更新后客户',
            'address' => null,
            'level' => null,
            'remark' => null,
            'contact' => [
                'id' => $contactId,
                'name' => '更新后联系人',
                'phone' => '13900000000',
            ],
        ])->assertOk()
            ->assertJsonPath('contact.id', $contactId)
            ->assertJsonPath('contact.name', '更新后联系人');

        $this->assertSame('更新后客户', ObjectRecord::findOrFail($customerId)->title);
        $this->assertSame('更新后联系人', ObjectRecord::findOrFail($contactId)->title);
    }

    public function test_project_customer_combined_validation_failure_does_not_update_customer(): void
    {
        $business = $this->userWithRole('business', '业务员');
        $created = $this->actingAs($business)->postJson('/project-customers', [
            'name' => '不可部分更新客户',
        ])->assertCreated();
        $customerId = $created->json('customer.id');

        $this->actingAs($business)->putJson("/project-customers/{$customerId}", [
            'name' => '不应保存的新名称',
            'contact' => ['name' => '', 'phone' => '13800138000'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('contact.name');

        $this->assertSame('不可部分更新客户', ObjectRecord::findOrFail($customerId)->title);
    }

    public function test_project_customer_combined_update_rejects_contact_from_another_customer(): void
    {
        $business = $this->userWithRole('business', '业务员');
        $first = $this->actingAs($business)->postJson('/project-customers', [
            'name' => '第一客户',
        ])->assertCreated();
        $second = $this->actingAs($business)->postJson('/project-customers', [
            'name' => '第二客户',
            'contact' => ['name' => '第二客户联系人'],
        ])->assertCreated();

        $this->actingAs($business)->putJson("/project-customers/{$first->json('customer.id')}", [
            'name' => '不应更新的第一客户',
            'contact' => [
                'id' => $second->json('contact.id'),
                'name' => '越权联系人',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('contact.id');

        $this->assertSame('第一客户', ObjectRecord::findOrFail($first->json('customer.id'))->title);
        $this->assertSame('第二客户联系人', ObjectRecord::findOrFail($second->json('contact.id'))->title);
    }

    public function test_finance_can_view_customer_table_but_cannot_modify_it_or_view_contact_table(): void
    {
        $finance = $this->userWithRole('finance', '财务');
        $customerObject = $this->object('customer');
        $customer = $customerObject->records()->create([
            'code' => 'CUST-FINANCE',
            'title' => '财务可见客户',
            'payload' => ['name' => '财务可见客户'],
            'created_by' => $finance->id,
        ]);

        $this->actingAs($finance)->get('/objects/customer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentObject.key', 'customer')
                ->where('can.create', false)
                ->where('can.update', false)
                ->where('can.delete', false));
        $this->actingAs($finance)->put("/records/{$customer->id}", [
            'payload' => ['name' => '不应修改'],
        ])->assertForbidden();
        $this->actingAs($finance)->get('/objects/customer_contact')->assertForbidden();
        $this->assertSame('财务可见客户', $customer->fresh()->title);
    }

    public function test_attachment_uploads_append_and_invalid_files_do_not_replace_history(): void
    {
        $admin = $this->userWithRole('admin', '管理员');
        $owner = $this->userWithRole('business', '业务员');
        $project = $this->project($owner);
        $contractObject = $this->object('contract');
        $this->actingAs($admin)->post("/objects/{$contractObject->id}", ['payload' => [
            ...$this->contractPayload($project, '未签署', 100000),
            'statement_attachments' => [UploadedFile::fake()->create('首份对账单.pdf', 20, 'application/pdf')],
        ]])->assertRedirect();
        $contract = $contractObject->records()->where('payload->project_id', $project->id)->firstOrFail();

        $this->actingAs($admin)->put("/records/{$contract->id}", ['payload' => [
            ...$contract->payload,
            'statement_attachments' => [
                UploadedFile::fake()->image('补充一.png'),
                UploadedFile::fake()->create('补充二.pdf', 20, 'application/pdf'),
            ],
            'processing_letter_attachments' => [],
            'contract_attachments' => [],
        ]])->assertRedirect();
        $this->assertCount(3, $contract->fresh()->payload['statement_attachments']);

        $this->actingAs($admin)->put("/records/{$contract->id}", ['payload' => [
            ...$contract->fresh()->payload,
            'statement_attachments' => [UploadedFile::fake()->create('恶意脚本.exe', 20, 'application/octet-stream')],
            'processing_letter_attachments' => [],
            'contract_attachments' => [],
        ]])->assertSessionHasErrors('payload.statement_attachments.0');
        $this->assertCount(3, $contract->fresh()->payload['statement_attachments']);
    }

    public function test_payment_reminder_uses_natural_month_reset_completion_and_reactivation_boundaries(): void
    {
        Carbon::setTestNow('2026-02-28 09:00:00');
        $admin = $this->userWithRole('admin', '管理员');
        $finance = $this->userWithRole('finance', '财务');
        $owner = $this->userWithRole('business', '业务员');
        $project = $this->project($owner, '月末回款项目', [
            'overall_status' => '已拿到加工函',
            'contract_status' => '已有加工函',
            'processing_letter_at' => '2026-01-31T09:00:00+08:00',
            'payment_reminder_anchor_at' => '2026-01-31T09:00:00+08:00',
            'payment_status' => '部分回款',
        ]);
        $sync = app(SyncProjectNotifications::class);
        $this->assertSame(1, $sync->handleProjects([$project->id])['triggered']);
        $this->assertSame(1, $project->fresh()->payload['collection_count']);

        $payload = [...$project->fresh()->payload, 'paid_amount' => 50000];
        $this->actingAs($finance)->put("/records/{$project->id}", ['payload' => $payload])->assertRedirect();
        $this->assertSame(0, ProjectNotification::query()->where('project_id', $project->id)->active()->count());

        Carbon::setTestNow('2026-03-28 09:00:00');
        $this->assertSame(1, $sync->handleProjects([$project->id])['triggered']);
        $this->assertSame(2, $project->fresh()->payload['collection_count']);

        $this->actingAs($finance)->put("/records/{$project->id}", ['payload' => [
            ...$project->fresh()->payload, 'payment_status' => '已回款',
        ]])->assertRedirect();
        $this->assertSame(0, ProjectNotification::query()->where('project_id', $project->id)->active()->count());

        Carbon::setTestNow('2026-04-02 09:00:00');
        $this->actingAs($finance)->put("/records/{$project->id}", ['payload' => [
            ...$project->fresh()->payload, 'payment_status' => '部分回款',
        ]])->assertRedirect();
        Carbon::setTestNow('2026-05-02 09:00:00');
        $this->assertSame(2, $sync->handleProjects([$project->id])['triggered']);
        $this->assertSame(3, $project->fresh()->payload['collection_count']);

        $this->actingAs($owner)->put("/records/{$project->id}", ['payload' => [
            ...$project->fresh()->payload, 'overall_status' => '已完成',
        ]])->assertRedirect();
        $this->assertSame(0, ProjectNotification::query()->where('project_id', $project->id)->active()->count());
        $this->assertSame(0, $sync->handleProjects([$project->id])['triggered']);
        $this->assertTrue(ProjectNotification::query()->where('user_id', $admin->id)->exists());
    }

    public function test_or_filters_never_expand_business_assignment_scope(): void
    {
        $owner = $this->userWithRole('business', '业务员甲');
        $other = $this->userWithRole('business', '业务员乙');
        $this->project($owner, '甲项目');
        $this->project($other, '乙项目');
        $query = http_build_query([
            'filter_logic' => 'or',
            'filters' => [['field' => 'name', 'operator' => 'contains', 'value' => '乙项目']],
        ]);

        $this->actingAs($owner)->get('/objects/project?'.$query)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('records.data', 0));
    }

    private function project(User $owner, string $name = '测试项目', array $overrides = []): ObjectRecord
    {
        $customer = ObjectRecord::create([
            'business_object_id' => $this->object('customer')->id,
            'code' => 'CUST-'.str()->uuid(),
            'title' => $name.'客户',
            'created_by' => $owner->id,
            'payload' => ['name' => $name.'客户'],
        ]);

        return ObjectRecord::create([
            'business_object_id' => $this->object('project')->id,
            'code' => 'PRJ-'.str()->uuid(),
            'title' => $name,
            'created_by' => $owner->id,
            'payload' => [
                'name' => $name,
                'project_no' => 'PRJ-TEST',
                'customer_id' => $customer->id,
                'customer_contact_ids' => [],
                'business_owner_user_id' => (string) $owner->id,
                'overall_status' => '投标中',
                'overall_status_changed_at' => now()->toISOString(),
                'contract_status' => '未签署',
                'collection_count' => 0,
                'remark' => '',
                ...$overrides,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function contractPayload(ObjectRecord $project, string $status, float $amount): array
    {
        return [
            'project_id' => $project->id,
            'status' => $status,
            'ctype' => '销售合同',
            'amount' => $amount,
            'contract_qty' => 1,
            'remark' => '',
            'processing_letter_attachments' => [],
            'contract_attachments' => [],
            'statement_attachments' => [],
        ];
    }

    private function object(string $key): BusinessObject
    {
        return BusinessObject::query()->where('key', $key)->firstOrFail();
    }

    private function userWithRole(string $role, string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $role.'-'.str()->uuid().'@example.com',
            'password' => Hash::make('password123'),
        ]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }
}
