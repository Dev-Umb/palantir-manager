<?php

namespace Tests\Feature;

use App\Actions\CreateObjectRecord;
use App\Actions\SyncXycMetadata;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\TenderNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenderConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SyncXycMetadata::class)->handle();
    }

    public function test_authorized_conversion_creates_one_equivalent_project_and_notifies_business_and_admin(): void
    {
        $tenderUser = $this->userWithRole('tender', '招投标专员');
        $business = $this->userWithRole('business', '接手业务员');
        $admin = $this->userWithRole('admin', '管理员');
        $tender = $this->createTender($tenderUser);

        $this->actingAs($tenderUser)
            ->get("/objects/tender?record={$tender->id}&mode=detail")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.convert', true)
                ->has('businessUsers', 1)
                ->where('businessUsers.0.id', $business->id));

        $this->actingAs($tenderUser)
            ->post("/records/{$tender->id}/convert-to-project", [
                'assignee_user_id' => $business->id,
            ])->assertRedirect();

        $freshTender = $tender->fresh();
        $project = ObjectRecord::query()
            ->whereKey($freshTender->payload['converted_project_id'])
            ->whereRelation('businessObject', 'key', 'project')
            ->firstOrFail();
        $this->assertSame('已中标', $freshTender->payload['status']);
        $this->assertSame('厂房钢结构标的', $project->payload['name']);
        $this->assertSame($tender->payload['customer_id'], $project->payload['customer_id']);
        $this->assertSame((string) $business->id, $project->payload['business_owner_user_id']);
        $this->assertSame([], $project->payload['informed_business_user_ids']);
        $this->assertSame('已中标', $project->payload['overall_status']);
        $this->assertSame('未签署', $project->payload['contract_status']);
        $this->assertSame(0, $project->payload['collection_count']);
        $this->assertSame($business->id, $project->created_by);
        $this->actingAs($business)
            ->get("/objects/project?record={$project->id}&mode=detail")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedRecord.id', $project->id)
                ->where('selectedRecord.title', $project->title));
        $this->actingAs($tenderUser)
            ->get("/objects/tender?record={$tender->id}&mode=detail")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedRecord.id', $tender->id));
        $this->assertEqualsCanonicalizing(
            [$business->id, $admin->id],
            TenderNotification::query()
                ->where('type', TenderNotification::TYPE_CONVERSION)
                ->pluck('user_id')
                ->all(),
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tender.converted',
            'subject_id' => $tender->id,
        ]);
        $this->actingAs($business)
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tenderNotifications.data', 1)
                ->where('tenderNotifications.data.0.project.code', $project->code)
                ->where('tenderNotifications.data.0.project_url', "/objects/project?record={$project->id}&mode=detail"));

        $this->actingAs($tenderUser)
            ->post("/records/{$tender->id}/convert-to-project", [
                'assignee_user_id' => $business->id,
            ])->assertRedirect();
        $this->assertSame(1, ObjectRecord::query()
            ->whereRelation('businessObject', 'key', 'project')
            ->count());
        $this->assertSame(2, TenderNotification::query()
            ->where('type', TenderNotification::TYPE_CONVERSION)
            ->count());

        $this->actingAs($tenderUser)
            ->delete("/records/{$tender->id}")
            ->assertSessionHasErrors('tender');
        $this->assertDatabaseHas('object_records', ['id' => $tender->id]);
    }

    public function test_conversion_requires_a_business_assignee_and_tender_update_permission(): void
    {
        $tenderUser = $this->userWithRole('tender', '招投标专员');
        $basic = $this->userWithRole('basic', '普通用户');
        $business = $this->userWithRole('business', '只读业务');
        $tender = $this->createTender($tenderUser);

        $this->actingAs($tenderUser)
            ->post("/records/{$tender->id}/convert-to-project", [
                'assignee_user_id' => $basic->id,
            ])
            ->assertSessionHasErrors('assignee_user_id');
        $this->assertArrayNotHasKey('converted_project_id', $tender->fresh()->payload);

        $this->actingAs($business)
            ->post("/records/{$tender->id}/convert-to-project", [
                'assignee_user_id' => $business->id,
            ])
            ->assertForbidden();
        $this->assertSame(0, ObjectRecord::query()
            ->whereRelation('businessObject', 'key', 'project')
            ->count());
    }

    public function test_generic_update_cannot_write_won_status(): void
    {
        $tenderUser = $this->userWithRole('tender', '招投标专员');
        $tender = $this->createTender($tenderUser);

        $payload = $tender->payload;
        $payload['status'] = '已中标';
        $this->actingAs($tenderUser)
            ->put("/records/{$tender->id}", ['payload' => $payload])
            ->assertSessionHasErrors('payload.status');

        $this->assertSame('跟踪中', $tender->fresh()->payload['status']);
        $this->assertSame(0, ObjectRecord::query()
            ->whereRelation('businessObject', 'key', 'project')
            ->count());
    }

    private function createTender(User $creator): ObjectRecord
    {
        $customerObject = BusinessObject::query()->where('key', 'customer')->firstOrFail();
        $customer = ObjectRecord::create([
            'business_object_id' => $customerObject->id,
            'code' => 'CUST-CONVERT-001',
            'title' => '流转客户',
            'payload' => ['name' => '流转客户'],
            'created_by' => $creator->id,
        ]);

        return app(CreateObjectRecord::class)->handle(
            BusinessObject::query()->where('key', 'tender')->firstOrFail(),
            [
                'name' => '厂房钢结构标的',
                'customer_id' => $customer->id,
                'register_deadline' => '2026-08-10T09:30',
                'purchase_deadline' => '2026-08-11T10:30',
                'submit_deadline' => '2026-08-12T14:00',
                'status' => '跟踪中',
            ],
            $creator,
        );
    }

    private function userWithRole(string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }
}
