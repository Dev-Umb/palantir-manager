<?php

namespace Tests\Feature;

use App\Actions\SyncXycMetadata;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenderManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SyncXycMetadata::class)->handle();
    }

    public function test_tender_role_can_manage_tenders_and_customers_without_customer_delete_permission(): void
    {
        $tenderUser = $this->userWithRole('tender');

        $this->assertTrue($tenderUser->canDo('object.tender.view'));
        $this->assertTrue($tenderUser->canDo('object.tender.create'));
        $this->assertTrue($tenderUser->canDo('object.tender.update'));
        $this->assertTrue($tenderUser->canDo('object.tender.delete'));
        $this->assertTrue($tenderUser->canDo('object.customer.view'));
        $this->assertTrue($tenderUser->canDo('object.customer.create'));
        $this->assertTrue($tenderUser->canDo('object.customer.update'));
        $this->assertFalse($tenderUser->canDo('object.customer.delete'));

        $business = $this->userWithRole('business');
        $this->assertTrue($business->canDo('object.tender.view'));
        $this->assertFalse($business->canDo('object.tender.update'));

        $basic = $this->userWithRole('basic');
        $this->actingAs($basic)->get('/objects/tender')->assertForbidden();
        $this->actingAs($business)->get('/objects/tender')->assertOk();
    }

    public function test_tender_creation_can_create_or_reuse_an_exact_customer_name(): void
    {
        $tenderUser = $this->userWithRole('tender');
        $tenderObject = BusinessObject::query()->where('key', 'tender')->firstOrFail();
        $payload = $this->validTenderPayload('现场新客户');

        $this->actingAs($tenderUser)
            ->post("/objects/{$tenderObject->id}", ['payload' => $payload])
            ->assertRedirect('/objects/tender');

        $customer = ObjectRecord::query()
            ->whereRelation('businessObject', 'key', 'customer')
            ->where('title', '现场新客户')
            ->firstOrFail();
        $tender = ObjectRecord::query()
            ->whereRelation('businessObject', 'key', 'tender')
            ->firstOrFail();
        $this->assertSame($customer->id, $tender->payload['customer_id']);
        $this->assertSame('跟踪中', $tender->payload['status']);
        $this->assertSame('未购买', $tender->payload['purchase_status']);
        $this->assertStringStartsWith('ZB-', $tender->code);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'object.create.related',
            'subject_id' => $customer->id,
        ]);

        $secondPayload = $this->validTenderPayload('现场新客户');
        $secondPayload['name'] = '第二个标的';
        $this->actingAs($tenderUser)
            ->post("/objects/{$tenderObject->id}", ['payload' => $secondPayload])
            ->assertRedirect('/objects/tender');

        $this->assertSame(1, ObjectRecord::query()
            ->whereRelation('businessObject', 'key', 'customer')
            ->where('title', '现场新客户')
            ->count());
    }

    public function test_tender_role_can_edit_but_cannot_delete_a_customer(): void
    {
        $tenderUser = $this->userWithRole('tender');
        $customerObject = BusinessObject::query()->where('key', 'customer')->firstOrFail();
        $customer = ObjectRecord::create([
            'business_object_id' => $customerObject->id,
            'code' => 'CUST-TEST-001',
            'title' => '前期客户',
            'payload' => ['name' => '前期客户'],
            'created_by' => $tenderUser->id,
        ]);

        $this->actingAs($tenderUser)
            ->put("/records/{$customer->id}", ['payload' => ['name' => '前期客户（更新）']])
            ->assertRedirect('/objects/customer');
        $this->assertSame('前期客户（更新）', $customer->fresh()->title);

        $this->actingAs($tenderUser)
            ->delete("/records/{$customer->id}")
            ->assertForbidden();
        $this->assertDatabaseHas('object_records', ['id' => $customer->id]);
    }

    public function test_generic_create_rejects_won_status_and_preserves_existing_object_definitions(): void
    {
        $tenderUser = $this->userWithRole('tender');
        $tenderObject = BusinessObject::query()->where('key', 'tender')->firstOrFail();
        $payload = $this->validTenderPayload('客户 A');
        $payload['status'] = '已中标';

        $this->actingAs($tenderUser)
            ->post("/objects/{$tenderObject->id}", ['payload' => $payload])
            ->assertSessionHasErrors('payload.status');
        $this->assertSame(0, $tenderObject->records()->count());

        $customer = BusinessObject::query()->where('key', 'customer')->firstOrFail();
        $project = BusinessObject::query()->where('key', 'project')->firstOrFail();
        $this->assertSame(['business', 'finance'], $customer->roles);
        $this->assertSame(['business', 'finance'], $project->roles);
    }

    /** @return array<string, string> */
    private function validTenderPayload(string $customer): array
    {
        return [
            'name' => '厂房钢结构招标',
            'customer_id' => $customer,
            'register_deadline' => '2026-08-04T09:30',
            'purchase_deadline' => '2026-08-05T10:15',
            'submit_deadline' => '2026-08-06T14:45',
            'status' => '跟踪中',
            'purchase_status' => '未购买',
        ];
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }
}
