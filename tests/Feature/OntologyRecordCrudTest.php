<?php

namespace Tests\Feature;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OntologyRecordCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_can_be_created_updated_exported_and_deleted_without_changing_payload_contract(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->actingAs($this->userWithRole('admin'));
        $customer = BusinessObject::where('key', 'customer')->firstOrFail();

        $this->post("/objects/{$customer->id}", ['payload' => ['name' => '回归客户', 'level' => 'A']])
            ->assertRedirect();
        $record = $customer->records()->where('payload->name', '回归客户')->firstOrFail();

        $this->put("/records/{$record->id}", ['payload' => ['name' => '回归客户更新', 'level' => 'B']])
            ->assertRedirect();
        $this->assertSame('回归客户更新', $record->fresh()->payload['name']);

        $this->get('/objects/customer/export.csv')->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->delete("/records/{$record->id}")->assertRedirect();
        $this->assertModelMissing($record);
    }

    public function test_validation_and_attachment_authorization_fail_closed(): void
    {
        Storage::fake('local');
        $this->seed(XycPrototypeSeeder::class);
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin);
        $customer = BusinessObject::where('key', 'customer')->firstOrFail();
        $this->post("/objects/{$customer->id}", ['payload' => []])->assertSessionHasErrors('payload.name');

        $drawing = BusinessObject::where('key', 'drawing')->firstOrFail();
        Storage::disk('local')->put('attachments/contract.pdf', '%PDF-1.4');
        $record = ObjectRecord::create([
            'business_object_id' => $drawing->id,
            'code' => 'TZ-AUTH',
            'title' => '附件授权',
            'payload' => ['name' => '附件授权', 'attachment' => 'attachments/contract.pdf'],
            'created_by' => $admin->id,
        ]);
        $this->get("/attachments/{$record->id}/attachment")->assertOk();

        $this->actingAs($this->userWithRole('basic'));
        $this->get("/attachments/{$record->id}/attachment")->assertForbidden();
    }

    public function test_project_customer_relation_can_be_updated(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->actingAs($this->userWithRole('admin'));
        $customerObject = BusinessObject::where('key', 'customer')->firstOrFail();
        $projectObject = BusinessObject::where('key', 'project')->firstOrFail();
        $oldCustomer = ObjectRecord::create([
            'business_object_id' => $customerObject->id,
            'code' => 'CUST-OLD',
            'title' => '原客户',
            'payload' => ['name' => '原客户'],
        ]);
        $newCustomer = ObjectRecord::create([
            'business_object_id' => $customerObject->id,
            'code' => 'CUST-NEW',
            'title' => '新客户',
            'payload' => ['name' => '新客户'],
        ]);
        $project = ObjectRecord::create([
            'business_object_id' => $projectObject->id,
            'code' => 'PRJ-CUSTOMER-EDIT',
            'title' => '客户关联修改回归',
            'payload' => [
                'name' => '客户关联修改回归',
                'customer_id' => $oldCustomer->id,
                '_snapshots' => [
                    'customer_id' => ['id' => $oldCustomer->id, 'label' => 'CUST-OLD · 原客户'],
                ],
            ],
        ]);

        $this->putJson("/records/{$project->id}", [
            'payload' => [
                'name' => '客户关联修改回归',
                'customer_id' => $newCustomer->id,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('record.payload.customer_id', $newCustomer->id)
            ->assertJsonPath('record.display.customer_id', 'CUST-NEW · 新客户');

        $this->assertSame($newCustomer->id, $project->fresh()->payload['customer_id']);
    }

    public function test_project_list_uses_the_current_customer_name_after_customer_update(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->actingAs($this->userWithRole('admin'));
        $customerObject = BusinessObject::where('key', 'customer')->firstOrFail();
        $projectObject = BusinessObject::where('key', 'project')->firstOrFail();
        $customer = ObjectRecord::create([
            'business_object_id' => $customerObject->id,
            'code' => 'CUST-LIVE',
            'title' => '更新前客户',
            'payload' => ['name' => '更新前客户'],
        ]);
        ObjectRecord::create([
            'business_object_id' => $projectObject->id,
            'code' => 'PRJ-LIVE-CUSTOMER',
            'title' => '客户名称同步回归',
            'payload' => [
                'name' => '客户名称同步回归',
                'customer_id' => $customer->id,
                '_snapshots' => [
                    'customer_id' => ['id' => $customer->id, 'label' => 'CUST-LIVE · 更新前客户'],
                ],
            ],
        ]);

        $this->put("/records/{$customer->id}", [
            'payload' => ['name' => '更新后客户'],
        ])->assertRedirect();

        $this->get('/objects/project?q=PRJ-LIVE-CUSTOMER')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('records.data', 1)
                ->where('records.data.0.payload.customer_id', $customer->id)
                ->where('records.data.0.display.customer_id', 'CUST-LIVE · 更新后客户'));
    }

    public function test_non_project_customer_relations_keep_their_saved_snapshot(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->actingAs($this->userWithRole('admin'));
        $customerObject = BusinessObject::where('key', 'customer')->firstOrFail();
        $contractObject = BusinessObject::where('key', 'contract')->firstOrFail();
        $customer = ObjectRecord::create([
            'business_object_id' => $customerObject->id,
            'code' => 'CUST-HISTORY',
            'title' => '历史客户名称',
            'payload' => ['name' => '历史客户名称'],
        ]);
        ObjectRecord::create([
            'business_object_id' => $contractObject->id,
            'code' => 'CONTRACT-HISTORY',
            'title' => 'CONTRACT-HISTORY',
            'payload' => [
                'customer_id' => $customer->id,
                '_snapshots' => [
                    'customer_id' => ['id' => $customer->id, 'label' => 'CUST-HISTORY · 历史客户名称'],
                ],
            ],
        ]);

        $customer->update([
            'title' => '当前客户名称',
            'payload' => ['name' => '当前客户名称'],
        ]);

        $this->get('/objects/contract?q=CONTRACT-HISTORY')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('records.data', 1)
                ->where('records.data.0.display.customer_id', 'CUST-HISTORY · 历史客户名称'));
    }

    private function userWithRole(string $role): User
    {
        $user = User::create(['name' => $role, 'email' => "{$role}-crud@example.com", 'password' => Hash::make('password123')]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());

        return $user;
    }
}
