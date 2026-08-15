<?php

namespace Tests\Feature;

use App\Actions\SyncXycMetadata;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectNumberEditingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SyncXycMetadata::class)->handle();
    }

    public function test_project_amounts_allow_negatives_and_all_project_numbers_are_rounded_to_two_decimals(): void
    {
        $admin = $this->userWithRole('admin');
        $owner = $this->userWithRole('business');
        $customer = $this->customer($admin);
        $projectObject = BusinessObject::query()->where('key', 'project')->firstOrFail();

        $response = $this->actingAs($admin)->postJson("/objects/{$projectObject->id}", [
            'payload' => $this->projectPayload($owner, $customer, [
                'weight' => 2.345,
                'signed_weight' => 1.005,
                'contract_amount' => -123.455,
                'occurred_amount' => -23.456,
                'paid_amount' => -3.454,
                'unpaid_amount' => -20.002,
                'reconciled_amount' => -4.445,
                'invoiced_amount' => -5.555,
                'uninvoiced_amount' => -6.666,
                'payment_progress' => 33.335,
            ]),
        ])->assertCreated();

        $project = ObjectRecord::query()->findOrFail($response->json('record.id'));

        $this->assertSame(2.35, $project->payload['weight']);
        $this->assertSame(1.01, $project->payload['signed_weight']);
        $this->assertSame(-123.46, $project->payload['contract_amount']);
        $this->assertSame(-23.46, $project->payload['occurred_amount']);
        $this->assertSame(-3.45, $project->payload['paid_amount']);
        $this->assertEquals(-20.0, $project->payload['unpaid_amount']);
        $this->assertSame(-4.45, $project->payload['reconciled_amount']);
        $this->assertSame(-5.56, $project->payload['invoiced_amount']);
        $this->assertSame(-6.67, $project->payload['uninvoiced_amount']);
        $this->assertSame(33.34, $project->payload['payment_progress']);
    }

    public function test_project_non_amount_boundaries_and_other_object_rules_remain_intact(): void
    {
        $admin = $this->userWithRole('admin');
        $owner = $this->userWithRole('business');
        $customer = $this->customer($admin);
        $projectObject = BusinessObject::query()->where('key', 'project')->firstOrFail();

        $this->actingAs($admin)->postJson("/objects/{$projectObject->id}", [
            'payload' => $this->projectPayload($owner, $customer, ['weight' => -0.01]),
        ])->assertUnprocessable()->assertJsonValidationErrors('payload.weight');

        $this->actingAs($admin)->postJson("/objects/{$projectObject->id}", [
            'payload' => $this->projectPayload($owner, $customer, ['signed_weight' => -0.01]),
        ])->assertUnprocessable()->assertJsonValidationErrors('payload.signed_weight');

        $this->actingAs($admin)->postJson("/objects/{$projectObject->id}", [
            'payload' => $this->projectPayload($owner, $customer, ['payment_progress' => 100.01]),
        ])->assertUnprocessable()->assertJsonValidationErrors('payload.payment_progress');

        $tender = BusinessObject::query()->where('key', 'tender')->firstOrFail();
        $this->assertSame(0, collect($tender->fields)->firstWhere('key', 'budget_amount')['min']);
    }

    public function test_successful_edit_redirects_to_the_supplied_filtered_project_list(): void
    {
        $admin = $this->userWithRole('admin');
        $owner = $this->userWithRole('business');
        $customer = $this->customer($admin);
        $project = $this->project($owner, $customer);
        $returnTo = '/objects/project?q=%E9%AB%98%E9%80%9F&sort=name&direction=asc&filter_logic=and&filters%5B0%5D%5Bfield%5D=overall_status&filters%5B0%5D%5Boperator%5D=equals&filters%5B0%5D%5Bvalue%5D=%E5%B7%B2%E4%B8%AD%E6%A0%87&page=2&per_page=10';

        $this->actingAs($admin)
            ->put("/records/{$project->id}?".http_build_query(['return_to' => $returnTo]), [
                'payload' => [...$project->payload, 'occurred_amount' => -88.885],
            ])
            ->assertRedirect($returnTo)
            ->assertSessionHasNoErrors();

        $this->assertSame(-88.89, $project->fresh()->payload['occurred_amount']);

        $this->actingAs($admin)
            ->put("/records/{$project->id}?".http_build_query(['return_to' => 'https://evil.example/steal']), [
                'payload' => $project->fresh()->payload,
            ])
            ->assertRedirect('/objects/project');
    }

    private function projectPayload(User $owner, ObjectRecord $customer, array $overrides = []): array
    {
        return [...[
            'name' => '数值精度测试项目',
            'customer_id' => $customer->id,
            'business_owner_user_id' => (string) $owner->id,
            'informed_business_user_ids' => [],
            'overall_status' => '投标中',
        ], ...$overrides];
    }

    private function customer(User $creator): ObjectRecord
    {
        $object = BusinessObject::query()->where('key', 'customer')->firstOrFail();

        return ObjectRecord::query()->create([
            'business_object_id' => $object->id,
            'code' => 'CUST-NUMBER',
            'title' => '数值测试客户',
            'payload' => ['name' => '数值测试客户'],
            'created_by' => $creator->id,
        ]);
    }

    private function project(User $owner, ObjectRecord $customer): ObjectRecord
    {
        $object = BusinessObject::query()->where('key', 'project')->firstOrFail();

        return ObjectRecord::query()->create([
            'business_object_id' => $object->id,
            'code' => 'XYC-NUMBER',
            'title' => '数值精度测试项目',
            'payload' => [
                ...$this->projectPayload($owner, $customer),
                'contract_status' => '未签署',
                'collection_count' => 0,
            ],
            'created_by' => $owner->id,
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
