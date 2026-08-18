<?php

namespace Tests\Feature;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BusinessSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_summary_is_a_read_only_projection_without_own_records(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->artisan('xyc:admin', [
            'email' => 'summary-admin@example.com',
            '--password' => 'password123',
        ])->assertSuccessful();

        $summary = BusinessObject::where('key', 'project_business_summary')->firstOrFail();
        $this->assertTrue($summary->read_only);
        $this->assertSame(0, $summary->records()->count());
        $this->assertSame([
            '负责业务员',
            '项目编号',
            '客户名称',
            '项目名称',
            '已发生金额',
            '已回款金额',
            '未回款金额',
        ], collect($summary->fields)->pluck('label')->all());
        $this->assertSame(
            ['view'],
            Permission::where('module', 'project_business_summary')->pluck('action')->all(),
        );

        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
        $admin = User::where('email', 'summary-admin@example.com')->firstOrFail();
        $this->actingAs($admin)
            ->get('/objects/project_business_summary')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentObject.key', 'project_business_summary')
                ->where('currentObject.read_only', true)
                ->where('records.data', fn ($records): bool => collect($records)->contains(
                    fn (array $record): bool => $record['id'] === $project->id
                        && $record['payload']['name'] === $project->payload['name'],
                ))
                ->where('can.create', false)
                ->where('can.update', false)
                ->where('can.delete', false));

        $this->post("/objects/{$summary->id}", ['payload' => ['name' => '禁止写入']])
            ->assertForbidden();
        $this->assertSame(0, $summary->records()->count());
    }

    public function test_salespeople_only_see_their_own_projects_across_summary_reads(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $salespersonA = $this->userWithRole('业务员甲', 'sales-a@example.com', 'business');
        $salespersonB = $this->userWithRole('业务员乙', 'sales-b@example.com', 'business');
        $customer = ObjectRecord::whereRelation('businessObject', 'key', 'customer')->firstOrFail();
        $ownProject = $this->createProject($salespersonA, $customer, 'XYC-SUMMARY-A', '甲方可见项目');
        $otherProject = $this->createProject($salespersonB, $customer, 'XYC-SUMMARY-B', '乙方隐藏项目');

        $this->actingAs($salespersonA)
            ->get('/objects/project_business_summary?q=XYC-SUMMARY&sort=project_no&direction=asc&per_page=25')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('records.data', 1)
                ->where('records.data.0.id', $ownProject->id)
                ->where('records.data.0.payload', [
                    'business_owner_user_id' => (string) $salespersonA->id,
                    'project_no' => 'XYC-SUMMARY-A',
                    'customer_id' => $customer->id,
                    'name' => '甲方可见项目',
                    'occurred_amount' => 500,
                    'paid_amount' => 200,
                    'unpaid_amount' => 300,
                ])
                ->where('records.data.0.display.business_owner_user_id', '业务员甲')
                ->where('records.data.0.payload.occurred_amount', 500)
                ->where('records.data.0.payload.paid_amount', 200)
                ->where('records.data.0.payload.unpaid_amount', 300));

        $this->get("/objects/project_business_summary?record={$otherProject->id}&mode=detail")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedRecordId', null)
                ->where('selectedRecord', null)
                ->has('records.data', 1)
                ->where('records.data.0.id', $ownProject->id));

        $export = $this->get('/objects/project_business_summary/export.csv');
        $export->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $export->streamedContent();
        $this->assertStringContainsString('业务员甲', $csv);
        $this->assertStringContainsString('甲方可见项目', $csv);
        $this->assertStringNotContainsString('乙方隐藏项目', $csv);

        $this->get("/objects/project?record={$otherProject->id}&mode=detail")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedRecordId', null)
                ->where('selectedRecord', null));
    }

    public function test_summary_view_permission_cannot_bypass_project_view_permission(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $basic = $this->userWithRole('基础用户', 'summary-basic@example.com', 'basic');
        $summaryPermission = Permission::where('key', 'object.project_business_summary.view')->firstOrFail();
        Role::where('name', 'basic')->firstOrFail()->permissions()->syncWithoutDetaching([$summaryPermission->id]);

        $this->actingAs($basic)
            ->get('/objects/project_business_summary')
            ->assertForbidden();
    }

    private function userWithRole(string $name, string $email, string $roleName): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email]);
        $user->roles()->sync([Role::where('name', $roleName)->firstOrFail()->id]);

        return $user;
    }

    private function createProject(
        User $salesperson,
        ObjectRecord $customer,
        string $code,
        string $name,
    ): ObjectRecord {
        $project = BusinessObject::where('key', 'project')->firstOrFail();

        return ObjectRecord::create([
            'business_object_id' => $project->id,
            'code' => $code,
            'title' => $name,
            'created_by' => $salesperson->id,
            'payload' => [
                'name' => $name,
                'project_no' => $code,
                'customer_id' => $customer->id,
                'business_owner_user_id' => (string) $salesperson->id,
                'occurred_amount' => 500,
                'paid_amount' => 200,
                'unpaid_amount' => 300,
            ],
        ]);
    }
}
