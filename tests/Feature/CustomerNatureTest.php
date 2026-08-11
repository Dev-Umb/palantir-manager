<?php

namespace Tests\Feature;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerNatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_nature_is_a_customer_select_and_a_project_lookup(): void
    {
        $this->seed(XycPrototypeSeeder::class);

        $customer = BusinessObject::query()->where('key', 'customer')->firstOrFail();
        $project = BusinessObject::query()->where('key', 'project')->firstOrFail();
        $customerField = collect($customer->fields)->firstWhere('key', 'customer_nature');
        $projectField = collect($project->fields)->firstWhere('key', 'customer_nature');

        $this->assertSame('select', $customerField['type']);
        $this->assertSame(['国央企', '私企'], $customerField['options']);
        $this->assertSame('lookup', $projectField['type']);
    }

    public function test_customer_crud_accepts_only_the_configured_natures(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->actingAs($this->admin());
        $customerObject = BusinessObject::query()->where('key', 'customer')->firstOrFail();

        $response = $this->postJson("/objects/{$customerObject->id}", [
            'payload' => [
                'name' => '客户性质 CRUD 回归',
                'customer_nature' => '国央企',
            ],
        ])->assertCreated()
            ->assertJsonPath('record.payload.customer_nature', '国央企')
            ->assertJsonPath('record.display.customer_nature', '国央企');

        $customer = ObjectRecord::query()->findOrFail($response->json('record.id'));

        $this->putJson("/records/{$customer->id}", [
            'payload' => [
                'name' => '客户性质 CRUD 回归',
                'customer_nature' => '外企',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('payload.customer_nature');

        $this->assertSame('国央企', $customer->fresh()->payload['customer_nature']);

        $this->putJson("/records/{$customer->id}", [
            'payload' => [
                'name' => '客户性质 CRUD 回归',
                'customer_nature' => '私企',
            ],
        ])->assertOk()
            ->assertJsonPath('record.payload.customer_nature', '私企');

        $this->deleteJson("/records/{$customer->id}")->assertOk();
        $this->assertModelMissing($customer);
    }

    public function test_project_reads_the_current_customer_nature_without_persisting_a_copy(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->actingAs($this->admin());
        $customerObject = BusinessObject::query()->where('key', 'customer')->firstOrFail();
        $projectObject = BusinessObject::query()->where('key', 'project')->firstOrFail();
        $customer = ObjectRecord::query()->create([
            'business_object_id' => $customerObject->id,
            'code' => 'CUST-NATURE-LIVE',
            'title' => '性质透传客户',
            'payload' => [
                'name' => '性质透传客户',
                'customer_nature' => '国央企',
            ],
        ]);
        $project = ObjectRecord::query()->create([
            'business_object_id' => $projectObject->id,
            'code' => 'XYC-NATURE-LIVE',
            'title' => '性质透传项目',
            'payload' => [
                'name' => '性质透传项目',
                'customer_id' => $customer->id,
                'remark' => '相邻字段必须保留',
            ],
        ]);

        $this->assertProjectNature($project, '国央企');

        $this->putJson("/records/{$project->id}", [
            'payload' => [
                ...$project->payload,
                'customer_nature' => '私企',
            ],
        ])->assertOk()
            ->assertJsonPath('record.payload.customer_nature', '国央企');

        $savedProjectPayload = $project->fresh()->payload;
        $this->assertArrayNotHasKey('customer_nature', $savedProjectPayload);
        $this->assertSame('相邻字段必须保留', $savedProjectPayload['remark']);

        $this->putJson("/records/{$customer->id}", [
            'payload' => [
                'name' => '性质透传客户',
                'customer_nature' => '私企',
            ],
        ])->assertOk();

        $this->assertProjectNature($project, '私企');
        $this->assertArrayNotHasKey('customer_nature', $project->fresh()->payload);
    }

    public function test_project_nature_is_empty_when_the_customer_has_no_nature_or_is_missing(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->actingAs($this->admin());
        $customerObject = BusinessObject::query()->where('key', 'customer')->firstOrFail();
        $projectObject = BusinessObject::query()->where('key', 'project')->firstOrFail();
        $customer = ObjectRecord::query()->create([
            'business_object_id' => $customerObject->id,
            'code' => 'CUST-NATURE-EMPTY',
            'title' => '未分类客户',
            'payload' => ['name' => '未分类客户'],
        ]);

        foreach ([
            ['code' => 'XYC-NATURE-EMPTY', 'customer_id' => $customer->id],
            ['code' => 'XYC-NATURE-MISSING', 'customer_id' => (string) Str::uuid()],
        ] as $definition) {
            ObjectRecord::query()->create([
                'business_object_id' => $projectObject->id,
                'code' => $definition['code'],
                'title' => $definition['code'],
                'payload' => [
                    'name' => $definition['code'],
                    'customer_id' => $definition['customer_id'],
                ],
            ]);

            $this->get("/objects/project?q={$definition['code']}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('records.data', 1)
                    ->where('records.data.0.payload.customer_nature', null)
                    ->where('records.data.0.display.customer_nature', null));
        }
    }

    private function assertProjectNature(ObjectRecord $project, string $nature): void
    {
        $this->get("/objects/project?q={$project->code}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('records.data', 1)
                ->where('records.data.0.payload.customer_nature', $nature)
                ->where('records.data.0.display.customer_nature', $nature));
    }

    private function admin(): User
    {
        $admin = User::query()->create([
            'name' => '客户性质管理员',
            'email' => 'customer-nature-admin@example.com',
            'password' => Hash::make('password123'),
        ]);
        $admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        return $admin;
    }
}
