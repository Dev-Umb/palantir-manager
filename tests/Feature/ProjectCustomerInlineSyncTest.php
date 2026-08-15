<?php

namespace Tests\Feature;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProjectCustomerInlineSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('xyc:sync-metadata')->assertSuccessful();
    }

    public function test_business_user_creates_project_customer_and_contacts_atomically(): void
    {
        $business = $this->userWithRole('business', '业务员甲');

        $response = $this->actingAs($business)->postJson('/objects/'.$this->object('project')->id, [
            'payload' => [
                'name' => '内联客户项目',
                'remark' => '项目备注必须保留',
                'customer_profile' => $this->profile(contacts: [
                    ['id' => null, 'name' => '联系人甲', 'phone' => '13800000001'],
                ]),
            ],
        ])->assertCreated()
            ->assertJsonPath('record.customer.name', '内联客户')
            ->assertJsonPath('record.customer.address', '武汉市江岸区')
            ->assertJsonPath('record.payload.customer_level', 'A')
            ->assertJsonPath('record.payload.customer_nature', '国央企');

        $project = ObjectRecord::query()->findOrFail($response->json('record.id'));
        $customer = ObjectRecord::query()->findOrFail($project->payload['customer_id']);
        $contact = ObjectRecord::query()->findOrFail($project->payload['customer_contact_ids'][0]);

        $this->assertSame('项目备注必须保留', $project->payload['remark']);
        $this->assertArrayNotHasKey('customer_profile', $project->payload);
        $this->assertSame('A', $customer->payload['level']);
        $this->assertSame('国央企', $customer->payload['customer_nature']);
        $this->assertSame($customer->id, $contact->payload['customer_id']);
        $this->assertSame('13800000001', $contact->payload['phone']);
    }

    public function test_preview_reports_conflicts_and_unconfirmed_save_rolls_back_every_write(): void
    {
        $business = $this->userWithRole('business', '业务员甲');
        $customer = $this->customer($business, level: 'B', nature: '私企');
        $projectCount = $this->object('project')->records()->count();
        $contactCount = $this->object('customer_contact')->records()->count();

        $this->actingAs($business)->postJson('/project-customer-profile/preview', $this->profile())
            ->assertOk()
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonFragment([
                'field' => 'level',
                'label' => '客户等级',
                'current' => 'B',
                'submitted' => 'A',
            ])
            ->assertJsonFragment([
                'field' => 'customer_nature',
                'label' => '客户性质',
                'current' => '私企',
                'submitted' => '国央企',
            ]);

        $this->actingAs($business)->postJson('/objects/'.$this->object('project')->id, [
            'payload' => [
                'name' => '冲突项目不得保存',
                'customer_profile' => $this->profile(contacts: [
                    ['id' => null, 'name' => '不得落库', 'phone' => '13900000000'],
                ]),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('payload.customer_profile');

        $this->assertSame($projectCount, $this->object('project')->records()->count());
        $this->assertSame($contactCount, $this->object('customer_contact')->records()->count());
        $this->assertSame('B', $customer->fresh()->payload['level']);
    }

    public function test_confirmed_save_reuses_unique_customer_and_contact_and_overwrites_shared_fields(): void
    {
        $business = $this->userWithRole('business', '业务员甲');
        $customer = $this->customer($business, level: 'B', nature: '私企');
        $contact = $this->contact($customer, $business, '联系人甲', '13800000001');

        $response = $this->actingAs($business)->postJson('/objects/'.$this->object('project')->id, [
            'payload' => [
                'name' => '复用客户项目',
                'customer_profile' => $this->profile(overwrite: true, contacts: [
                    ['id' => null, 'name' => '联系人甲', 'phone' => '13800000001'],
                ]),
            ],
        ])->assertCreated();

        $project = ObjectRecord::query()->findOrFail($response->json('record.id'));
        $this->assertSame($customer->id, $project->payload['customer_id']);
        $this->assertSame([$contact->id], $project->payload['customer_contact_ids']);
        $this->assertSame('A', $customer->fresh()->payload['level']);
        $this->assertSame('国央企', $customer->fresh()->payload['customer_nature']);
        $this->assertSame('客户备注必须保留', $customer->fresh()->payload['remark']);
        $this->assertSame(1, $this->object('customer')->records()->where('title', '内联客户')->count());
        $this->assertSame(1, $this->object('customer_contact')->records()->where('title', '联系人甲')->count());
    }

    public function test_removing_contact_from_project_only_unlinks_it_from_that_project(): void
    {
        $business = $this->userWithRole('business', '业务员甲');
        $customer = $this->customer($business);
        $contact = $this->contact($customer, $business, '保留联系人', '13800000002');
        $project = $this->project($business, $customer, [$contact->id]);

        $this->actingAs($business)->putJson('/records/'.$project->id, [
            'payload' => [
                ...$project->payload,
                'customer_profile' => $this->profile(customerId: $customer->id, contacts: []),
            ],
        ])->assertOk()
            ->assertJsonPath('record.payload.customer_contact_ids', []);

        $this->assertSame([], $project->fresh()->payload['customer_contact_ids']);
        $this->assertModelExists($contact->fresh());
        $this->assertSame($customer->id, $contact->fresh()->payload['customer_id']);
    }

    public function test_same_customer_name_with_different_address_creates_a_separate_customer(): void
    {
        $business = $this->userWithRole('business', '业务员甲');
        $this->customer($business, address: '武汉市江岸区');

        $response = $this->actingAs($business)->postJson('/objects/'.$this->object('project')->id, [
            'payload' => [
                'name' => '同名不同地址项目',
                'customer_profile' => $this->profile(address: '武汉市武昌区'),
            ],
        ])->assertCreated();

        $project = ObjectRecord::query()->findOrFail($response->json('record.id'));
        $this->assertSame(2, $this->object('customer')->records()->where('title', '内联客户')->count());
        $this->assertSame('武汉市武昌区', ObjectRecord::query()->findOrFail($project->payload['customer_id'])->payload['address']);
    }

    public function test_finance_keeps_existing_project_write_scope_but_cannot_submit_customer_profile(): void
    {
        $business = $this->userWithRole('business', '业务员甲');
        $finance = $this->userWithRole('finance', '财务甲');
        $customer = $this->customer($business);
        $project = $this->project($business, $customer);

        $this->actingAs($finance)->putJson('/records/'.$project->id, [
            'payload' => [...$project->payload, 'paid_amount' => 1200.25],
        ])->assertOk();
        $this->assertSame(1200.25, $project->fresh()->payload['paid_amount']);

        $this->actingAs($finance)->putJson('/records/'.$project->id, [
            'payload' => [...$project->fresh()->payload, 'customer_profile' => $this->profile(customerId: $customer->id)],
        ])->assertForbidden();
        $this->assertSame($customer->id, $project->fresh()->payload['customer_id']);
    }

    /** @return array<string, mixed> */
    private function profile(
        ?string $customerId = null,
        string $address = '武汉市江岸区',
        bool $overwrite = false,
        array $contacts = [],
    ): array {
        return [
            'customer_id' => $customerId,
            'name' => '内联客户',
            'address' => $address,
            'level' => 'A',
            'customer_nature' => '国央企',
            'overwrite_confirmed' => $overwrite,
            'contacts' => $contacts,
        ];
    }

    private function customer(
        User $creator,
        string $address = '武汉市江岸区',
        string $level = 'A',
        string $nature = '国央企',
    ): ObjectRecord {
        return ObjectRecord::query()->create([
            'business_object_id' => $this->object('customer')->id,
            'code' => 'CUST-'.str()->uuid(),
            'title' => '内联客户',
            'created_by' => $creator->id,
            'payload' => [
                'name' => '内联客户',
                'address' => $address,
                'level' => $level,
                'customer_nature' => $nature,
                'remark' => '客户备注必须保留',
            ],
        ]);
    }

    private function contact(ObjectRecord $customer, User $creator, string $name, string $phone): ObjectRecord
    {
        return ObjectRecord::query()->create([
            'business_object_id' => $this->object('customer_contact')->id,
            'code' => 'CONTACT-'.str()->uuid(),
            'title' => $name,
            'created_by' => $creator->id,
            'payload' => ['name' => $name, 'phone' => $phone, 'customer_id' => $customer->id],
        ]);
    }

    private function project(User $owner, ObjectRecord $customer, array $contactIds = []): ObjectRecord
    {
        return ObjectRecord::query()->create([
            'business_object_id' => $this->object('project')->id,
            'code' => 'XYC-'.str()->uuid(),
            'title' => '联系人解绑项目',
            'created_by' => $owner->id,
            'payload' => [
                'name' => '联系人解绑项目',
                'customer_id' => $customer->id,
                'customer_contact_ids' => $contactIds,
                'business_owner_user_id' => (string) $owner->id,
            ],
        ]);
    }

    private function object(string $key): BusinessObject
    {
        return BusinessObject::query()->where('key', $key)->firstOrFail();
    }

    private function userWithRole(string $role, string $name): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $role.'-'.str()->uuid().'@example.com',
            'password' => Hash::make('password123'),
        ]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }
}
