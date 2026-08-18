<?php

namespace Tests\Feature;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BusinessCustomerVisibilityTest extends TestCase
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

    public function test_business_customer_scope_uses_owned_projects_and_creator_owned_unlinked_customers(): void
    {
        $business = $this->userWithRole('business', '业务员甲');
        $other = $this->userWithRole('business', '业务员乙');
        $owned = $this->customer($other, '本人项目客户');
        $shared = $this->customer($other, '共享项目客户');
        $creatorButForeign = $this->customer($business, '创建后转给他人');
        $ownUnlinked = $this->customer($business, '本人未关联客户');
        $otherUnlinked = $this->customer($other, '他人未关联客户');

        $this->project($business, $owned, '本人项目');
        $this->project($business, $shared, '共享项目甲');
        $this->project($other, $shared, '共享项目乙');
        $this->project($other, $creatorButForeign, '他人项目');

        $this->actingAs($business)->get('/objects/customer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.data', fn ($records): bool => collect($records)->pluck('id')->sort()->values()->all() === collect([
                    $owned->id,
                    $shared->id,
                    $ownUnlinked->id,
                ])->sort()->values()->all()));

        $this->actingAs($business)->get("/objects/customer?record={$creatorButForeign->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selectedRecord', null));
        $this->actingAs($business)->get('/objects/customer?q=他人未关联客户')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('records.data', 0));
        $this->actingAs($business)->getJson('/relation-options?source_object=project&field=customer_id')
            ->assertOk()
            ->assertJsonPath('items', fn (array $items): bool => collect($items)->pluck('id')->sort()->values()->all() === collect([
                $owned->id,
                $shared->id,
                $ownUnlinked->id,
            ])->sort()->values()->all());

        $projectCount = $this->object('project')->records()->count();
        $this->actingAs($business)->post('/objects/project', [
            'payload' => [
                'name' => '不可关联项目',
                'customer_id' => $otherUnlinked->id,
                'overall_status' => '投标中',
            ],
        ])->assertSessionHasErrors('payload.customer_id');
        $this->assertSame($projectCount, $this->object('project')->records()->count());
        $this->actingAs($business)->delete("/records/{$creatorButForeign->id}")->assertForbidden();
        $this->assertModelExists($creatorButForeign);

        $csv = $this->actingAs($business)->get('/objects/customer/export.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('本人项目客户', $csv);
        $this->assertStringContainsString('共享项目客户', $csv);
        $this->assertStringContainsString('本人未关联客户', $csv);
        $this->assertStringNotContainsString('创建后转给他人', $csv);
        $this->assertStringNotContainsString('他人未关联客户', $csv);
    }

    public function test_customer_contacts_and_direct_customer_management_inherit_customer_scope(): void
    {
        $business = $this->userWithRole('business', '业务员甲');
        $other = $this->userWithRole('business', '业务员乙');
        $visibleCustomer = $this->customer($business, '可见客户');
        $unlinkedCustomer = $this->customer($business, '本人未关联客户');
        $hiddenCustomer = $this->customer($other, '隐藏客户');
        $this->project($business, $visibleCustomer, '可见项目');
        $this->project($other, $hiddenCustomer, '隐藏项目');
        $visibleContact = $this->contact($business, $visibleCustomer, '可见联系人');
        $unlinkedContact = $this->contact($business, $unlinkedCustomer, '未关联客户联系人');
        $hiddenContact = $this->contact($other, $hiddenCustomer, '隐藏联系人');

        $this->actingAs($business)->get('/objects/customer_contact')->assertForbidden();
        $this->actingAs($business)->get('/objects/customer_contact/export.csv')->assertNotFound();

        $this->actingAs($business)->getJson("/project-customers/{$visibleCustomer->id}")
            ->assertOk()
            ->assertJsonPath('customer.id', $visibleCustomer->id)
            ->assertJsonPath('customer.contacts.0.id', $visibleContact->id);
        $this->actingAs($business)->getJson("/project-customers/{$unlinkedCustomer->id}")
            ->assertOk()
            ->assertJsonPath('customer.contacts.0.id', $unlinkedContact->id);
        $this->actingAs($business)->getJson("/project-customers/{$hiddenCustomer->id}")
            ->assertNotFound();
        $this->actingAs($business)->putJson("/project-customers/{$hiddenCustomer->id}", [
            'name' => '越权修改客户',
        ])->assertNotFound();
        $this->actingAs($business)->postJson("/project-customers/{$hiddenCustomer->id}/contacts", [
            'name' => '越权新增联系人',
        ])->assertNotFound();
        $this->actingAs($business)->putJson("/project-customers/{$hiddenCustomer->id}/contacts/{$hiddenContact->id}", [
            'name' => '越权修改联系人',
        ])->assertNotFound();
        $this->actingAs($business)->put("/records/{$hiddenContact->id}", [
            'payload' => [...$hiddenContact->payload, 'name' => '通用接口越权修改'],
        ])->assertForbidden();

        $this->assertSame('隐藏客户', $hiddenCustomer->fresh()->title);
        $this->assertSame('隐藏联系人', $hiddenContact->fresh()->title);
    }

    public function test_administrator_finance_and_tender_customer_scope_remains_unchanged(): void
    {
        $creator = $this->userWithRole('business', '业务员');
        $customers = collect([
            $this->customer($creator, '客户一'),
            $this->customer($creator, '客户二'),
        ]);

        foreach (['admin', 'finance', 'tender'] as $role) {
            $user = $this->userWithRole($role, $role);
            $this->actingAs($user)->get('/objects/customer')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('records.data', fn ($records): bool => collect($records)->pluck('id')->sort()->values()->all()
                        === $customers->pluck('id')->sort()->values()->all()));
        }
    }

    public function test_project_customer_options_prioritize_unlinked_customers_and_keep_the_current_selection(): void
    {
        $admin = $this->userWithRole('admin', '管理员');
        $owner = $this->userWithRole('business', '业务员');

        Carbon::setTestNow('2026-08-01 09:00:00');
        $linkedOlder = $this->customer($owner, '已关联较早');
        $this->project($owner, $linkedOlder, '较早项目');
        Carbon::setTestNow('2026-08-02 09:00:00');
        $unlinkedOlder = $this->customer($owner, '未关联较早');
        Carbon::setTestNow('2026-08-03 09:00:00');
        $linkedNewer = $this->customer($owner, '已关联较新');
        $this->project($owner, $linkedNewer, '较新项目');
        Carbon::setTestNow('2026-08-04 09:00:00');
        $unlinkedNewer = $this->customer($owner, '未关联较新');

        $this->actingAs($admin)->getJson('/relation-options?source_object=project&field=customer_id')
            ->assertOk()
            ->assertJsonPath('items.0.id', $unlinkedNewer->id)
            ->assertJsonPath('items.1.id', $unlinkedOlder->id)
            ->assertJsonPath('items.2.id', $linkedNewer->id)
            ->assertJsonPath('items.3.id', $linkedOlder->id);

        for ($index = 0; $index < 51; $index++) {
            Carbon::setTestNow(Carbon::parse('2026-08-05 09:00:00')->addSeconds($index));
            $this->customer($owner, "占位客户{$index}");
        }
        $editingProject = $this->project($owner, $linkedOlder, '编辑中的项目');
        $this->actingAs($admin)->getJson('/relation-options?'.http_build_query([
            'source_object' => 'project',
            'field' => 'customer_id',
            'editing_record' => $editingProject->id,
        ]))
            ->assertOk()
            ->assertJsonPath('items.0.title', '占位客户50')
            ->assertJsonPath('items.49.title', '占位客户1')
            ->assertJsonPath('items.50', null)
            ->assertJsonPath('items', fn (array $items): bool => ! collect($items)->contains('id', $linkedOlder->id));

        $this->actingAs($admin)->get('/objects/project?'.http_build_query([
            'record' => $editingProject->id,
            'mode' => 'edit',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('relationOptions.customer_id.selectedItems.0.id', $linkedOlder->id));

        $this->actingAs($admin)->getJson('/relation-options?'.http_build_query([
            'source_object' => 'project',
            'field' => 'customer_id',
            'q' => '未关联',
        ]))
            ->assertOk()
            ->assertJsonPath('items.0.id', $unlinkedNewer->id)
            ->assertJsonPath('items.1.id', $unlinkedOlder->id);
    }

    public function test_business_login_defaults_to_projects_while_intended_and_other_roles_are_preserved(): void
    {
        $business = $this->userWithRole('business', '业务员');
        $this->post('/login', [
            'email' => $business->email,
            'password' => 'password123',
        ])->assertRedirect('/objects/project');

        auth()->logout();
        $this->withSession(['url.intended' => '/objects/customer'])
            ->post('/login', [
                'email' => $business->email,
                'password' => 'password123',
            ])->assertRedirect('/objects/customer');

        foreach (['admin', 'finance', 'tender'] as $role) {
            auth()->logout();
            $user = $this->userWithRole($role, "{$role}账号");
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'password123',
            ])->assertRedirect('/');
        }

        auth()->logout();
        $multiRole = $this->userWithRole('business', '多角色账号');
        $multiRole->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->post('/login', [
            'email' => $multiRole->email,
            'password' => 'password123',
        ])->assertRedirect('/');
        $this->actingAs($business)->get('/')->assertOk();
    }

    private function customer(User $creator, string $name): ObjectRecord
    {
        return ObjectRecord::create([
            'business_object_id' => $this->object('customer')->id,
            'code' => 'CUST-'.str()->uuid(),
            'title' => $name,
            'payload' => ['name' => $name],
            'created_by' => $creator->id,
        ]);
    }

    private function contact(User $creator, ObjectRecord $customer, string $name): ObjectRecord
    {
        return ObjectRecord::create([
            'business_object_id' => $this->object('customer_contact')->id,
            'code' => 'CONTACT-'.str()->uuid(),
            'title' => $name,
            'payload' => ['name' => $name, 'customer_id' => $customer->id],
            'created_by' => $creator->id,
        ]);
    }

    private function project(User $owner, ObjectRecord $customer, string $name): ObjectRecord
    {
        return ObjectRecord::create([
            'business_object_id' => $this->object('project')->id,
            'code' => 'PRJ-'.str()->uuid(),
            'title' => $name,
            'payload' => [
                'name' => $name,
                'project_no' => 'PRJ-TEST',
                'customer_id' => $customer->id,
                'customer_contact_ids' => [],
                'business_owner_user_id' => (string) $owner->id,
                'informed_business_user_ids' => [],
                'overall_status' => '投标中',
                'contract_status' => '未签署',
                'collection_count' => 0,
                'remark' => '',
            ],
            'created_by' => $owner->id,
        ]);
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
