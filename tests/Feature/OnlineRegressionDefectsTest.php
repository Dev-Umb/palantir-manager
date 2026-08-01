<?php

namespace Tests\Feature;

use App\Models\BusinessObject;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnlineRegressionDefectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_retained_project_store_accepts_key_and_existing_numeric_id_contract(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->actingAs($this->userWithRole('admin'));
        $project = BusinessObject::where('key', 'project')->firstOrFail();
        $customer = BusinessObject::where('key', 'customer')->firstOrFail()->records()->firstOrFail();
        $owner = User::where('email', 'business@xyc.test')->firstOrFail();

        $this->postJson('/objects/project', [
            'payload' => ['name' => '按 Key 创建项目', 'customer_id' => $customer->id, 'business_owner_user_id' => (string) $owner->id],
        ])->assertCreated();
        $this->postJson("/objects/{$project->id}", [
            'payload' => ['name' => '按 ID 创建项目', 'customer_id' => $customer->id, 'business_owner_user_id' => (string) $owner->id],
        ])->assertCreated();

        $this->assertTrue($project->records()->where('payload->name', '按 Key 创建项目')->exists());
        $this->assertTrue($project->records()->where('payload->name', '按 ID 创建项目')->exists());
    }

    public function test_unknown_object_and_malformed_record_ids_return_sanitized_not_found_responses(): void
    {
        config(['app.debug' => false]);
        $this->seed(XycPrototypeSeeder::class);
        $this->actingAs($this->userWithRole('admin'));

        $this->postJson('/objects/nonexistent-key', ['payload' => ['name' => '不存在']])
            ->assertNotFound()
            ->assertExactJson(['message' => '记录不存在或已被删除。'])
            ->assertDontSee('App\\\\Models');

        $this->putJson('/records/not-a-uuid', ['payload' => []])
            ->assertNotFound()
            ->assertExactJson(['message' => '记录不存在或已被删除。'])
            ->assertDontSee('App\\\\Models');

        $this->putJson('/records/'.Str::uuid(), ['payload' => []])
            ->assertNotFound()
            ->assertExactJson(['message' => '记录不存在或已被删除。'])
            ->assertDontSee('App\\\\Models');
    }

    public function test_inertia_not_found_response_uses_the_safe_error_page(): void
    {
        config(['app.debug' => false]);
        $this->seed(XycPrototypeSeeder::class);
        $this->actingAs($this->userWithRole('admin'));

        $response = $this->withHeader('X-Inertia', 'true')
            ->put('/records/'.Str::uuid(), ['payload' => []]);

        $response
            ->assertNotFound()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Error')
            ->assertJsonPath('props.status', 404)
            ->assertJsonPath('props.message', '记录不存在或已被删除。');
    }

    public function test_malformed_relation_ids_return_chinese_validation_errors_before_lookup(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->actingAs($this->userWithRole('production'));

        $requisitionResponse = $this->postJson('/requests', [
            'requester' => '生产',
            'material_id' => '1',
            'qty' => 2,
            'unit' => '吨',
            'urgency' => '普通',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('material_id');
        $this->assertSame('关联记录格式不正确', $requisitionResponse->json('errors')['material_id'][0]);

        $this->actingAs($this->userWithRole('admin'));
        $owner = User::where('email', 'business@xyc.test')->firstOrFail();
        $projectResponse = $this->postJson('/objects/project', [
            'payload' => [
                'name' => '非法关联项目',
                'customer_id' => 'not-a-uuid',
                'business_owner_user_id' => (string) $owner->id,
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payload.customer_id');
        $this->assertSame(
            '关联记录格式不正确',
            $projectResponse->json('errors')['payload.customer_id'][0],
        );

        $this->postJson('/objects/purchase', [
            'payload' => [
                'items' => [[
                    'material_id' => 'not-a-uuid',
                    'qty' => 1,
                ]],
            ],
        ])->assertNotFound();
    }

    public function test_existing_authorization_and_required_field_errors_remain_403_and_422(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $project = BusinessObject::where('key', 'project')->firstOrFail();

        $this->actingAs($this->userWithRole('basic'));
        $this->postJson("/objects/{$project->id}", ['payload' => ['name' => '无权创建']])
            ->assertForbidden();

        $this->actingAs($this->userWithRole('admin'));
        $this->postJson("/objects/{$project->id}", ['payload' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payload.name');
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => $role,
            'email' => "{$role}-online-defects@example.com",
            'password' => Hash::make('password123'),
        ]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());

        return $user;
    }
}
