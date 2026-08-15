<?php

namespace Tests\Feature;

use App\Actions\BuildFilteredRecordSubtotal;
use App\Actions\SyncXycMetadata;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FilteredRecordSubtotalTest extends TestCase
{
    use RefreshDatabase;

    private int $recordSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(SyncXycMetadata::class)->handle();
    }

    public function test_only_the_last_page_exposes_a_subtotal_for_every_filtered_record(): void
    {
        $admin = $this->userWithRole('admin');

        foreach (range(1, 26) as $index) {
            $paid = match ($index) {
                1 => null,
                2 => '待补',
                3 => -5,
                4 => 0,
                default => 10,
            };
            $this->record('project', [
                'name' => "筛选项目{$index}",
                'overall_status' => '已中标',
                'occurred_amount' => $index,
                'paid_amount' => $paid,
            ]);
        }
        $this->record('project', [
            'name' => '筛选外项目',
            'overall_status' => '投标中',
            'occurred_amount' => 99999,
            'paid_amount' => 99999,
        ]);
        $recordCount = ObjectRecord::count();
        $query = [
            'per_page' => 25,
            'filters' => [[
                'field' => 'overall_status',
                'operator' => 'equals',
                'value' => '已中标',
            ]],
        ];

        $this->actingAs($admin)
            ->get('/objects/project?'.http_build_query([...$query, 'page' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.total', 26)
                ->has('records.data', 25)
                ->where('subtotal', null));

        $this->get('/objects/project?'.http_build_query([...$query, 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.total', 26)
                ->has('records.data', 1)
                ->where('subtotal.label', '小计')
                ->where('subtotal.values.occurred_amount', fn (mixed $value): bool => (float) $value === 351.0)
                ->where('subtotal.values.paid_amount', fn (mixed $value): bool => (float) $value === 215.0)
                ->where('subtotal.values.unpaid_amount', fn (mixed $value): bool => (float) $value === 0.0));

        $this->assertSame($recordCount, ObjectRecord::count());
    }

    public function test_subtotal_uses_the_same_project_visibility_scope_as_the_business_user(): void
    {
        $business = $this->userWithRole('business');
        $otherBusiness = $this->userWithRole('business');
        $this->record('project', [
            'name' => '我的项目',
            'business_owner_user_id' => (string) $business->id,
            'occurred_amount' => 100,
        ], $business);
        $this->record('project', [
            'name' => '他人项目',
            'business_owner_user_id' => (string) $otherBusiness->id,
            'occurred_amount' => 900,
        ], $otherBusiness);

        $this->actingAs($business)
            ->get('/objects/project')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.total', 1)
                ->where('subtotal.values.occurred_amount', fn (mixed $value): bool => (float) $value === 100.0));
    }

    public function test_item_numbers_are_summed_without_repeating_record_numbers(): void
    {
        $purchase = BusinessObject::query()->where('key', 'purchase')->firstOrFail();
        $this->record('purchase', [
            'record_total' => 10,
            'items' => [
                ['qty' => 2],
                ['qty' => '3.5'],
                ['qty' => '待补'],
            ],
        ]);
        $this->record('purchase', [
            'record_total' => 20,
            'items' => [
                ['qty' => -1],
                ['qty' => null],
            ],
        ]);

        $subtotal = app(BuildFilteredRecordSubtotal::class)->handle($purchase->records(), [
            ['key' => 'record_total', 'type' => 'number'],
            ['key' => 'qty', 'type' => 'number', 'scope' => 'item'],
        ]);

        $this->assertNotNull($subtotal);
        $this->assertSame(30.0, $subtotal['values']['record_total']);
        $this->assertSame(4.5, $subtotal['values']['qty']);
    }

    public function test_a_table_without_number_fields_does_not_expose_a_subtotal(): void
    {
        $admin = $this->userWithRole('admin');
        $this->record('customer', ['name' => '无数字客户']);

        $this->actingAs($admin)
            ->get('/objects/customer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.total', 1)
                ->where('subtotal', null));
    }

    private function record(string $objectKey, array $payload, ?User $creator = null): ObjectRecord
    {
        $this->recordSequence++;
        $object = BusinessObject::query()->where('key', $objectKey)->firstOrFail();

        return ObjectRecord::query()->create([
            'business_object_id' => $object->id,
            'code' => sprintf('SUB-%03d', $this->recordSequence),
            'title' => (string) ($payload['name'] ?? "小计测试{$this->recordSequence}"),
            'payload' => $payload,
            'created_by' => $creator?->id,
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
