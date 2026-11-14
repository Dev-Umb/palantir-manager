<?php

namespace Tests\Feature;

use App\Actions\SyncProjectNotifications;
use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\StoredAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MiniappContractUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->artisan('xyc:sync-metadata')->assertSuccessful();
    }

    public function test_new_contract_accepts_unknown_amount_without_ai_and_existing_upload_preserves_fields_and_files(): void
    {
        [$user, $project] = $this->fixture();
        $this->upload($user, $project, ['status' => '未签署', 'amount' => '', 'ctype' => '销售合同'])->assertOk();
        $contract = $this->contract($project);
        $this->assertNull($contract->payload['amount']);
        $this->assertSame('未签署', $contract->payload['status']);
        $before = $contract->payload;
        $this->upload($user, $project, ['contract_id' => $contract->id, 'amount' => 999, 'status' => '已签署'])->assertOk();
        $after = $contract->fresh()->payload;
        $this->assertCount(2, $after['contract_attachments']);
        $this->assertSame($before['contract_attachments'][0], $after['contract_attachments'][0]);
        unset($before['contract_attachments'], $after['contract_attachments']);
        $this->assertSame($before, $after);
        $this->assertSame('保留备注', $project->fresh()->payload['remark']);
        $this->assertDatabaseCount('ai_contract_intakes', 0);
        $this->assertSame(1, AuditLog::where('action', 'object.update.project_contract')->count());
        $this->actingAs($user)->get('/attachments/'.$contract->id.'/contract_attachments/0')->assertOk();
    }

    public function test_new_signed_contract_keeps_zero_amount_and_other_contracts(): void
    {
        [$user, $project] = $this->fixture();
        $this->upload($user, $project, ['status' => '未签署'])->assertOk();
        $first = $this->contract($project);
        $before = $first->payload;
        $this->upload($user, $project, ['status' => '已签署', 'amount' => '0', 'signed_date' => '2026-11-14'])->assertOk();
        $this->assertSame($before, $first->fresh()->payload);
        $second = ObjectRecord::where('payload->project_id', $project->id)->where('id', '!=', $first->id)->firstOrFail();
        $this->assertEquals(0, $second->payload['amount']);
        $this->assertSame('已签署', $second->payload['status']);
    }

    public function test_unauthorized_informed_finance_and_cross_project_writes_are_rejected(): void
    {
        [$owner, $project] = $this->fixture();
        [$other, $otherProject] = $this->fixture();
        $this->upload($owner, $project, ['status' => '未签署'])->assertOk();
        $contract = $this->contract($project);
        $this->upload($other, $project, ['contract_id' => $contract->id])->assertForbidden();
        $project->update(['payload' => [...$project->payload, 'informed_business_user_ids' => [(string) $other->id]]]);
        $this->upload($other, $project, ['contract_id' => $contract->id])->assertForbidden();
        $this->upload($other, $otherProject, ['contract_id' => $contract->id])->assertNotFound();
        $other->roles()->sync([Role::where('name', 'finance')->firstOrFail()->id]);
        $this->upload($other->fresh(), $otherProject, ['status' => '未签署'])->assertForbidden();
        $this->assertCount(1, $contract->fresh()->payload['contract_attachments']);
        $this->assertDatabaseCount('stored_attachments', 1);
    }

    public function test_file_validation_count_and_failure_leave_original_data_intact(): void
    {
        [$user, $project] = $this->fixture();
        $url = '/projects/'.$project->id.'/contract-uploads';
        foreach ([UploadedFile::fake()->create('evil.exe', 1, 'application/octet-stream'), UploadedFile::fake()->create('large.pdf', 102401, 'application/pdf')] as $file) {
            $this->actingAs($user)->postJson($url, ['status' => '未签署', 'file' => $file])->assertUnprocessable();
        }
        $this->upload($user, $project, ['status' => '未签署'])->assertOk();
        $contract = $this->contract($project);
        $contract->update(['payload' => [...$contract->payload, 'contract_attachments' => array_fill(0, 20, $contract->payload['contract_attachments'][0])]]);
        $before = $contract->payload;
        $this->upload($user, $project, ['contract_id' => $contract->id])->assertUnprocessable();
        $this->assertSame($before, $contract->fresh()->payload);
        $this->assertDatabaseCount('stored_attachments', 1);
    }

    public function test_latest_contract_values_are_retained_and_storage_is_cleaned_on_failure(): void
    {
        [$user, $project] = $this->fixture();
        $this->upload($user, $project, ['status' => '未签署'])->assertOk();
        $contract = $this->contract($project);
        $contract->update(['payload' => [...$contract->payload, 'amount' => 123, 'remark' => '刚修改的资料']]);
        $this->upload($user, $project, ['contract_id' => $contract->id, 'amount' => 1])->assertOk();
        $this->assertEquals(123, $contract->fresh()->payload['amount']);
        $this->assertSame('刚修改的资料', $contract->fresh()->payload['remark']);
        $before = $contract->fresh()->payload;
        $files = Storage::disk('local')->allFiles();
        $this->mock(SyncProjectNotifications::class)->shouldReceive('handleProjects')->andThrow(new \RuntimeException('notification failure'));
        $this->upload($user, $project, ['contract_id' => $contract->id])->assertServerError();
        $this->assertSame($before, $contract->fresh()->payload);
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame(2, StoredAttachment::count());
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'business')->firstOrFail());
        $project = ObjectRecord::create([
            'business_object_id' => BusinessObject::where('key', 'project')->firstOrFail()->id,
            'code' => 'PRJ-'.str()->uuid(), 'title' => '上传测试项目', 'created_by' => $user->id,
            'payload' => ['name' => '上传测试项目', 'business_owner_user_id' => (string) $user->id, 'overall_status' => '投标中', 'remark' => '保留备注'],
        ]);

        return [$user, $project];
    }

    private function contract(ObjectRecord $project): ObjectRecord
    {
        return ObjectRecord::whereRelation('businessObject', 'key', 'contract')->where('payload->project_id', $project->id)->sole();
    }

    private function upload(User $user, ObjectRecord $project, array $data): TestResponse
    {
        return $this->actingAs($user)->postJson('/projects/'.$project->id.'/contract-uploads', [...$data, 'file' => UploadedFile::fake()->createWithContent('合同.pdf', '%PDF-1.4 contract')]);
    }
}
