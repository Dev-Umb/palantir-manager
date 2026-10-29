<?php

namespace Tests\Feature;

use App\Actions\ReimbursementBatchService;
use App\Jobs\ProcessReimbursementInvoice;
use App\Models\Permission;
use App\Models\ReimbursementBatch;
use App\Models\ReimbursementBatchFile;
use App\Models\ReimbursementDraft;
use App\Models\ReimbursementUpload;
use App\Models\ReimbursementWorkflow;
use App\Models\Role;
use App\Models\StoredAttachment;
use App\Models\User;
use App\Support\BatchInvoiceRecognition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReimbursementBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['reimbursements.batch_enabled' => true, 'services.business_files.disk' => 'local']);
        Storage::fake('local');
        Queue::fake();
        $this->owner = User::factory()->create();
        $role = Role::create(['name' => 'batch-owner', 'label' => '测试']);
        $p = Permission::firstOrCreate(['key' => 'reimbursements.submit'], ['module' => 'reimbursements', 'action' => 'submit', 'label' => '提交']);
        $role->permissions()->attach($p);
        $this->owner->roles()->attach($role);
    }

    private function recognized(ReimbursementBatch $batch, string $type = '住宿费', bool $reviewed = true): ReimbursementBatchFile
    {
        $upload = ReimbursementUpload::factory()->create(['user_id' => $batch->user_id]);

        return ReimbursementBatchFile::factory()->create(['batch_id' => $batch->id, 'status' => 'recognized', 'upload_id' => $upload->id, 'reviewed' => $reviewed, 'result' => ['type' => $type, 'amount' => '100.01', 'invoice_date' => '2026-10-29', 'description' => '测试发票'], 'issues' => $reviewed ? [] : ['请核对']]);
    }

    public function test_reconciliation_keeps_waiting_files_in_large_batches(): void
    {
        $batch = ReimbursementBatch::factory()->create(['user_id' => $this->owner->id, 'status' => 'processing']);
        $waiting = ReimbursementBatchFile::factory()->create(['batch_id' => $batch->id, 'status' => 'queued', 'updated_at' => now()->subHour()]);
        $stuck = ReimbursementBatchFile::factory()->create(['batch_id' => $batch->id, 'status' => 'processing', 'updated_at' => now()->subHour()]);
        $this->artisan('reimbursements:reconcile-batches')->assertSuccessful();
        $this->assertSame('queued', $waiting->fresh()->status);
        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame('processing', $batch->fresh()->status);
    }

    public function test_reconciliation_preserves_all_tos_objects_including_unconfirmed_uploads(): void
    {
        $batch = ReimbursementBatch::factory()->create(['user_id' => $this->owner->id]);
        $file = ReimbursementBatchFile::factory()->create(['batch_id' => $batch->id, 'disk' => 'local', 'status' => 'uploading', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        Storage::disk('local')->put($file->object_key, 'original');
        Storage::disk('local')->put($file->object_key.'.upload', 'staging');
        $this->artisan('reimbursements:reconcile-batches')->assertSuccessful();
        Storage::disk('local')->assertExists([$file->object_key, $file->object_key.'.upload']);
        $this->assertSame('uploading', $file->fresh()->status);
    }

    public function test_owner_only_create_and_retained_options(): void
    {
        $id = (string) Str::uuid();
        $this->actingAs($this->owner)->postJson('/reimbursements/batches', ['id' => $id])->assertCreated();
        $this->postJson('/reimbursements/batches', ['id' => $id])->assertCreated();
        $this->assertDatabaseCount('reimbursement_batches', 1);
        $this->getJson('/reimbursements/options')->assertOk()->assertJsonFragment(['住宿费'])->assertJsonFragment(['差旅交通']);
        $other = User::factory()->create();
        $other->roles()->sync($this->owner->roles->pluck('id'));
        $this->actingAs($other)->getJson('/reimbursements/batches/'.$id)->assertForbidden();
        $this->postJson('/reimbursements/batches', ['id' => $id])->assertForbidden();
        $this->getJson('/reimbursements/batches')->assertJsonPath('records.total', 0);
    }

    public function test_size_and_count_limits_and_reservation_idempotency(): void
    {
        $batch = ReimbursementBatch::factory()->create(['user_id' => $this->owner->id]);
        $s = app(ReimbursementBatchService::class);
        $data = ['id' => (string) Str::uuid(), 'name' => '票.pdf', 'size' => 20971520, 'mime' => 'application/pdf'];
        $s->reserve($batch, $data);
        $s->reserve($batch, $data);
        $this->assertDatabaseCount('reimbursement_batch_files', 1);
        ReimbursementBatchFile::factory()->count(299)->create(['batch_id' => $batch->id, 'size' => 1]);
        $this->expectException(HttpException::class);
        $s->reserve($batch, [...$data, 'id' => (string) Str::uuid()]);
    }

    public function test_confirm_validates_content_and_same_batch_duplicates(): void
    {
        $batch = ReimbursementBatch::factory()->create(['user_id' => $this->owner->id]);
        $s = app(ReimbursementBatchService::class);
        $bytes = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
        $make = function () use ($s, $batch, $bytes) {
            $f = $s->reserve($batch, ['id' => (string) Str::uuid(), 'name' => '票.pdf', 'size' => strlen($bytes), 'mime' => 'application/pdf']);
            Storage::disk('local')->put($f->object_key.'.upload', $bytes);

            return $f;
        };
        $file = $make();
        $s->confirm($file);
        $s->confirm($file);
        $this->assertDatabaseCount('reimbursement_uploads', 1);
        $this->assertSame('uploaded', $file->fresh()->status);
        $this->assertSame('private', Storage::disk('local')->getVisibility($file->object_key));
        Storage::disk('local')->put($file->object_key.'.upload', 'changed');
        $this->assertSame($bytes, Storage::disk('local')->get($file->object_key));
        $duplicate = $make();
        $s->confirm($duplicate);
        $this->assertSame('excluded', $duplicate->fresh()->status);
        $bad = $make();
        Storage::disk('local')->put($bad->object_key.'.upload', 'bad');
        $this->actingAs($this->owner)->postJson('/reimbursements/batches/'.$batch->id.'/files/'.$bad->id.'/confirm')->assertUnprocessable();
    }

    public function test_start_only_selected_uploads_and_repeated_start_does_not_dispatch_again(): void
    {
        $batch = ReimbursementBatch::factory()->create(['user_id' => $this->owner->id]);
        $a = ReimbursementBatchFile::factory()->create(['batch_id' => $batch->id, 'status' => 'uploaded']);
        $b = ReimbursementBatchFile::factory()->create(['batch_id' => $batch->id]);
        $s = app(ReimbursementBatchService::class);
        $s->start($batch, [$a->id]);
        $s->start($batch, [$a->id]);
        Queue::assertPushed(ProcessReimbursementInvoice::class, 1);
        $this->assertSame('excluded', $b->fresh()->status);
    }

    public function test_splits_300_into_six_drafts_and_preserves_manual_edits_on_retry(): void
    {
        $batch = ReimbursementBatch::factory()->create(['user_id' => $this->owner->id, 'status' => 'processing']);
        for ($i = 0; $i < 300; $i++) {
            $this->recognized($batch);
        }
        $s = app(ReimbursementBatchService::class);
        $s->finish($batch);
        $this->assertDatabaseCount('reimbursement_drafts', 6);
        foreach (ReimbursementDraft::all() as $d) {
            $this->assertCount(50, $d->payload['items']);
        }
        $draft = ReimbursementDraft::first();
        $p = $draft->payload;
        $p['note'] = '人工填写';
        $draft->update(['payload' => $p]);
        $s->finish($batch);
        $this->assertSame('人工填写', $draft->fresh()->payload['note']);
        $this->recognized($batch);
        $s->finish($batch);
        $this->assertDatabaseCount('reimbursement_drafts', 7);
        $this->assertDatabaseCount('reimbursement_notices', 0);
    }

    public function test_partial_failure_keeps_success_and_separate_types_and_requires_review(): void
    {
        $batch = ReimbursementBatch::factory()->create(['user_id' => $this->owner->id, 'status' => 'processing']);
        $a = $this->recognized($batch, '住宿费', false);
        $this->recognized($batch, '加油费');
        $failed = ReimbursementBatchFile::factory()->create(['batch_id' => $batch->id, 'status' => 'failed']);
        $s = app(ReimbursementBatchService::class);
        $s->finish($batch);
        $this->assertDatabaseCount('reimbursement_drafts', 2);
        $this->assertSame('attention', $batch->fresh()->status);
        $draft = ReimbursementDraft::find($a->fresh()->draft_id);
        $this->actingAs($this->owner)->getJson('/reimbursements/drafts/'.$draft->id)->assertJsonPath('draft.batch_pending_review', 1);
        $this->assertFalse($a->fresh()->reviewed);
        $this->assertSame('failed', $failed->fresh()->status);
    }

    public function test_job_failure_and_validated_result_never_overwrite_draft(): void
    {
        $batch = ReimbursementBatch::factory()->create(['user_id' => $this->owner->id, 'status' => 'processing']);
        $upload = ReimbursementUpload::factory()->create(['user_id' => $this->owner->id]);
        $f = ReimbursementBatchFile::factory()->create(['batch_id' => $batch->id, 'upload_id' => $upload->id, 'status' => 'queued']);
        $recognizer = \Mockery::mock(BatchInvoiceRecognition::class);
        $recognizer->shouldReceive('recognize')->once()->andReturn(['type' => '话费', 'amount' => '50.00', 'invoice_date' => '2026-10-29', 'description' => '话费', 'warnings' => [], 'uncertain' => false, 'invoice_number' => '12345678', 'invoice_code' => null]);
        $job = new ProcessReimbursementInvoice($f->id);
        $job->handle($recognizer, app(ReimbursementBatchService::class));
        $job->handle($recognizer, app(ReimbursementBatchService::class));
        $this->assertDatabaseCount('reimbursement_drafts', 1);
        $this->assertSame('recognized', $f->fresh()->status);
    }

    public function test_old_app_receives_safe_edit_notice_and_manual_types_remain_editable(): void
    {
        $batch = ReimbursementBatch::factory()->create(['user_id' => $this->owner->id, 'status' => 'processing']);
        $this->recognized($batch, '住宿费');
        $this->recognized($batch, '差旅交通');
        app(ReimbursementBatchService::class)->finish($batch);
        foreach (ReimbursementDraft::all() as $draft) {
            $response = $this->actingAs($this->owner)->withHeader('User-Agent', 'Dart/3.10 (dart:io)')->getJson('/reimbursements/drafts/'.$draft->id);
            if ($draft->payload['type'] === '住宿费') {
                $response->assertStatus(409)->assertJsonFragment(['message' => '此草稿使用新增报销类型，请在小程序中编辑并提交。']);
            } else {
                $response->assertOk();
            }
        }
    }

    public function test_confirming_batch_draft_submits_existing_items_without_extra_task_interaction(): void
    {
        $reviewer = User::factory()->create();
        ReimbursementWorkflow::findOrFail(1)->update(['definition' => ['nodes' => [['name' => '审批', 'mode' => 'any', 'people' => [$reviewer->id]]], 'cc' => [], 'triggers' => []]]);
        $batch = ReimbursementBatch::factory()->create(['user_id' => $this->owner->id, 'status' => 'processing']);
        $file = $this->recognized($batch, '住宿费', false);
        $upload = ReimbursementUpload::findOrFail($file->upload_id);
        $stored = StoredAttachment::findOrFail($upload->stored_attachment_id);
        $bytes = '%PDF synthetic';
        Storage::disk('local')->put('test-confirm.pdf', $bytes);
        $stored->update(['disk' => 'local', 'object_key' => 'test-confirm.pdf', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        app(ReimbursementBatchService::class)->finish($batch);
        $draft = ReimbursementDraft::findOrFail($file->fresh()->draft_id);
        $data = [...$draft->payload, 'note' => '业务员填写用途', 'draft_id' => $draft->id, 'draft_revision' => $draft->revision, 'submission_key' => (string) Str::uuid()];
        $this->actingAs($this->owner)->postJson('/reimbursements', $data)->assertUnprocessable();
        $this->postJson('/reimbursements', [...$data, 'batch_review_confirmed' => true])->assertCreated();
        $this->assertTrue($file->fresh()->reviewed);
        $this->assertNotNull($draft->fresh()->version_id);
    }

    public function test_disabled_feature_and_unprivileged_access(): void
    {
        config(['reimbursements.batch_enabled' => false]);
        $this->actingAs($this->owner)->getJson('/reimbursements/batches')->assertStatus(503);
        config(['reimbursements.batch_enabled' => true]);
        $this->actingAs(User::factory()->create())->getJson('/reimbursements/batches')->assertForbidden();
    }
}
