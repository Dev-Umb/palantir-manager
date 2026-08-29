<?php

namespace Tests\Feature;

use App\Actions\SyncProjectNotifications;
use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProjectContractMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->artisan('xyc:sync-metadata')->assertSuccessful();
    }

    public function test_project_creation_synchronizes_multiple_contracts_and_all_attachment_categories(): void
    {
        $admin = $this->userWithRole('admin', '管理员');
        $owner = $this->userWithRole('business', '负责业务员');
        $customer = $this->customer($owner, '项目合同客户');

        $this->actingAs($admin)->post('/objects/'.$this->object('project')->id, [
            'payload' => [
                'name' => '多合同新项目',
                'customer_id' => $customer->id,
                'business_owner_user_id' => (string) $owner->id,
                'overall_status' => '投标中',
            ],
            'contracts' => [
                [
                    ...$this->contractData('已有加工函', 100000),
                    'processing_letter_attachments' => [UploadedFile::fake()->create('加工函.pdf', 20, 'application/pdf')],
                    'statement_attachments' => [UploadedFile::fake()->create('对账单.pdf', 20, 'application/pdf')],
                ],
                [
                    ...$this->contractData('已签署', 200000),
                    'contract_attachments' => [UploadedFile::fake()->create('合同.pdf', 20, 'application/pdf')],
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $project = ObjectRecord::query()
            ->whereRelation('businessObject', 'key', 'project')
            ->where('title', '多合同新项目')
            ->firstOrFail();
        $contracts = $this->contractsFor($project);

        $this->assertCount(2, $contracts);
        $this->assertSame('部分签署', $project->payload['contract_status']);
        $this->assertSame('已拿到加工函', $project->payload['overall_status']);
        $this->assertSame($customer->id, $contracts->first()->payload['customer_id']);
        $this->assertSame($project->id, $contracts->first()->payload['project_id']);
        $this->assertSame($project->code, $contracts->first()->payload['project_no']);
        $this->assertCount(1, $contracts->firstWhere('payload.status', '已有加工函')->payload['processing_letter_attachments']);
        $this->assertCount(1, $contracts->firstWhere('payload.status', '已有加工函')->payload['statement_attachments']);
        $this->assertCount(1, $contracts->firstWhere('payload.status', '已签署')->payload['contract_attachments']);
        $this->assertSame(2, AuditLog::query()->where('action', 'object.create.project_contract')->count());
    }

    public function test_project_update_appends_attachments_and_only_deletes_explicit_contract_ids(): void
    {
        $owner = $this->userWithRole('business', '负责业务员');
        $project = $this->project($owner);

        $this->updateProjectContracts($owner, $project, [[
            ...$this->contractData('未签署', 100000),
            'statement_attachments' => [UploadedFile::fake()->create('首份对账单.pdf', 20, 'application/pdf')],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $first = $this->contractsFor($project)->sole();

        $this->updateProjectContracts($owner, $project, [
            [
                ...$this->contractData('已签署', 120000),
                'id' => $first->id,
                'contract_attachments' => [UploadedFile::fake()->create('正式合同.pdf', 20, 'application/pdf')],
                'statement_attachments' => [UploadedFile::fake()->create('补充对账单.pdf', 20, 'application/pdf')],
            ],
            $this->contractData('未签署', 80000),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $first->refresh();
        $this->assertCount(1, $first->payload['contract_attachments']);
        $this->assertCount(2, $first->payload['statement_attachments']);
        $this->assertCount(2, $this->contractsFor($project));
        $this->assertSame('部分签署', $project->fresh()->payload['contract_status']);

        $second = $this->contractsFor($project)->firstWhere('id', '!=', $first->id);
        $this->updateProjectContracts($owner, $project, [
            [
                ...$this->contractData('已签署', 120000),
                'id' => $first->id,
            ],
        ], [$second->id])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertModelMissing($second);
        $this->assertModelExists($first);
        $this->assertCount(1, $first->fresh()->payload['contract_attachments']);
        $this->assertCount(2, $first->fresh()->payload['statement_attachments']);
        $this->assertSame('已签署', $project->fresh()->payload['contract_status']);
    }

    public function test_invalid_evidence_and_cross_project_ids_roll_back_without_losing_history(): void
    {
        $owner = $this->userWithRole('business', '负责业务员');
        $firstProject = $this->project($owner, '第一项目');
        $secondProject = $this->project($owner, '第二项目');
        $this->updateProjectContracts($owner, $firstProject, [[
            ...$this->contractData('未签署', 100000),
            'statement_attachments' => [UploadedFile::fake()->create('历史对账单.pdf', 20, 'application/pdf')],
        ]])->assertRedirect();
        $contract = $this->contractsFor($firstProject)->sole();

        $this->updateProjectContracts($owner, $firstProject, [[
            ...$this->contractData('已签署', 100000),
            'id' => $contract->id,
        ]])->assertSessionHasErrors('contracts.0.contract_attachments');
        $this->assertSame('未签署', $contract->fresh()->payload['status']);
        $this->assertCount(1, $contract->fresh()->payload['statement_attachments']);

        $this->updateProjectContracts($owner, $secondProject, [[
            ...$this->contractData('未签署', 500000),
            'id' => $contract->id,
        ]])->assertSessionHasErrors('contracts');
        $this->assertSame($firstProject->id, $contract->fresh()->payload['project_id']);
        $this->assertCount(0, $this->contractsFor($secondProject));
    }

    public function test_project_contract_maintenance_respects_project_scope_and_role_boundary(): void
    {
        $owner = $this->userWithRole('business', '负责业务员');
        $otherBusiness = $this->userWithRole('business', '其他业务员');
        $finance = $this->userWithRole('finance', '财务');
        $project = $this->project($owner);

        $this->updateProjectContracts($owner, $project, [$this->contractData('未签署', 100000)])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertCount(1, $this->contractsFor($project));

        $this->updateProjectContracts($otherBusiness, $project, [$this->contractData('未签署', 200000)])
            ->assertForbidden();
        $this->updateProjectContracts($finance, $project, [$this->contractData('未签署', 300000)])
            ->assertForbidden();
        $this->assertCount(1, $this->contractsFor($project));
        $this->assertSame(100000.0, (float) $this->contractsFor($project)->sole()->payload['amount']);
    }

    public function test_database_failure_rolls_back_project_contracts_and_cleans_only_new_uploads(): void
    {
        $owner = $this->userWithRole('business', '负责业务员');
        $project = $this->project($owner);
        $originalPayload = $project->payload;
        $notifications = Mockery::mock(SyncProjectNotifications::class);
        $notifications->shouldReceive('handleProjects')->once()->andThrow(new RuntimeException('forced rollback'));
        $this->app->instance(SyncProjectNotifications::class, $notifications);

        $this->updateProjectContracts($owner, $project, [[
            ...$this->contractData('已签署', 100000),
            'contract_attachments' => [UploadedFile::fake()->create('回滚合同.pdf', 20, 'application/pdf')],
        ]])->assertServerError();

        $this->assertSame($originalPayload, $project->fresh()->payload);
        $this->assertCount(0, $this->contractsFor($project));
        $this->assertSame([], Storage::disk('local')->allFiles('attachments'));
    }

    public function test_contract_table_is_read_only_while_queries_exports_and_project_detail_remain_available(): void
    {
        $admin = $this->userWithRole('admin', '管理员');
        $owner = $this->userWithRole('business', '负责业务员');
        $project = $this->project($owner);
        $this->updateProjectContracts($owner, $project, [[
            ...$this->contractData('已签署', 100000),
            'contract_attachments' => [UploadedFile::fake()->create('合同.pdf', 20, 'application/pdf')],
        ]])->assertRedirect();
        $contract = $this->contractsFor($project)->sole();
        $contractObject = $this->object('contract');

        $this->actingAs($admin)->post("/objects/{$contractObject->id}", [
            'payload' => ['amount' => 1],
        ])->assertForbidden();
        $this->actingAs($admin)->put("/records/{$contract->id}", [
            'payload' => [...$contract->payload, 'amount' => 2],
        ])->assertForbidden();
        $this->actingAs($admin)->delete("/records/{$contract->id}")->assertForbidden();

        $this->actingAs($admin)->get('/objects/contract')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.create', false)
                ->where('can.update', false)
                ->where('can.delete', false)
                ->has('records.data', 1));
        $this->actingAs($admin)->get('/objects/contract/export.csv')->assertOk();
        $this->actingAs($admin)->get("/objects/project?record={$project->id}&mode=detail")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.manage_contracts', true)
                ->where('can.view_contracts', true)
                ->has('selectedRecord.contracts', 1)
                ->where('selectedRecord.contracts.0.id', $contract->id)
                ->where('selectedRecord.contracts.0.payload.contract_attachments.0', "/attachments/{$contract->id}/contract_attachments/0"));
    }

    /** @return array<string, mixed> */
    private function contractData(string $status, float $amount): array
    {
        return [
            'status' => $status,
            'ctype' => '销售合同',
            'amount' => $amount,
            'signed_date' => null,
            'contract_chase_record' => '',
            'contract_qty' => 1,
            'remark' => '',
            'processing_letter_attachments' => [],
            'contract_attachments' => [],
            'statement_attachments' => [],
        ];
    }

    private function updateProjectContracts(User $user, ObjectRecord $project, array $contracts, array $deletedIds = []): TestResponse
    {
        return $this->actingAs($user)->post("/records/{$project->id}", [
            '_method' => 'put',
            'payload' => $project->fresh()->payload,
            'contracts' => $contracts,
            'deleted_contract_ids' => $deletedIds,
        ]);
    }

    private function project(User $owner, string $name = '测试项目'): ObjectRecord
    {
        $customer = $this->customer($owner, $name.'客户');

        return ObjectRecord::create([
            'business_object_id' => $this->object('project')->id,
            'code' => 'PRJ-'.str()->uuid(),
            'title' => $name,
            'created_by' => $owner->id,
            'payload' => [
                'name' => $name,
                'project_no' => 'PRJ-TEST',
                'customer_id' => $customer->id,
                'customer_contact_ids' => [],
                'business_owner_user_id' => (string) $owner->id,
                'overall_status' => '投标中',
                'overall_status_changed_at' => now()->toISOString(),
                'contract_status' => '未签署',
                'collection_count' => 0,
                'remark' => '',
            ],
        ]);
    }

    private function customer(User $creator, string $name): ObjectRecord
    {
        return ObjectRecord::create([
            'business_object_id' => $this->object('customer')->id,
            'code' => 'CUST-'.str()->uuid(),
            'title' => $name,
            'created_by' => $creator->id,
            'payload' => ['name' => $name],
        ]);
    }

    /** @return Collection<int, ObjectRecord> */
    private function contractsFor(ObjectRecord $project): Collection
    {
        return ObjectRecord::query()
            ->whereRelation('businessObject', 'key', 'contract')
            ->where('payload->project_id', $project->id)
            ->orderBy('created_at')
            ->get();
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
