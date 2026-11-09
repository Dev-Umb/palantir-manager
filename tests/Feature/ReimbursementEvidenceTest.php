<?php

namespace Tests\Feature;

use App\Actions\ReimbursementDraftService;
use App\Ai\Agents\ReimbursementEvidenceAgent;
use App\Jobs\RecognizeReimbursementEvidence;
use App\Models\Permission;
use App\Models\ReimbursementUpload;
use App\Models\Role;
use App\Models\StoredAttachment;
use App\Models\User;
use App\Support\InvoiceMemoryWorkspace;
use App\Support\ReimbursementEvidenceRecognition;
use App\Support\ReimbursementEvidenceSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReimbursementEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'evidence-'.$user->id, 'label' => '测试']);
        $role->permissions()->attach(Permission::firstOrCreate(['key' => 'reimbursements.submit'], ['module' => 'reimbursements', 'action' => 'submit', 'label' => '提交']));
        $user->roles()->attach($role);

        return $user;
    }

    private function candidate(array $overrides = []): array
    {
        return [...['page' => 1, 'region' => '上半页', 'kind' => 'invoice', 'status' => 'issued', 'currency' => 'CNY', 'amount' => '101.00', 'date' => '2026-11-07', 'number' => '12345678', 'description' => '住宿费', 'uncertain' => false, 'warnings' => []], ...$overrides];
    }

    public function test_multiple_candidates_keep_exact_amounts_and_region_sources(): void
    {
        $result = app(ReimbursementEvidenceRecognition::class)->normalize(['items' => [$this->candidate(), $this->candidate(['amount' => '202.1', 'region' => '下半页', 'number' => '87654321'])], 'page_issues' => []], [1, 2]);
        $this->assertSame(['101.00', '202.10'], array_column($result['items'], 'amount'));
        $this->assertSame(['1-1', '1-2'], array_column($result['items'], 'id'));
        $this->assertSame(2, $result['issues'][0]['page']);
    }

    public function test_batch_skips_only_explicit_non_evidence_and_preserves_receipts_and_errors(): void
    {
        $response = ['items' => [$this->candidate(['kind' => 'receipt', 'amount' => '60.00'])], 'page_issues' => [['page' => 3, 'message' => '金额模糊']], 'skipped_pages' => [2]];
        $result = app(ReimbursementEvidenceRecognition::class)->normalize($response, [1, 2, 3, 4], true);
        $this->assertSame('60.00', $result['items'][0]['amount']);
        $this->assertNotContains(2, array_column($result['issues'], 'page'));
        $this->assertContains(3, array_column($result['issues'], 'page'));
        $this->assertContains(4, array_column($result['issues'], 'page'));
        $single = app(ReimbursementEvidenceRecognition::class)->normalize($response, [1, 2, 3, 4]);
        $this->assertContains(2, array_column($single['issues'], 'page'));
    }

    public function test_skipped_page_cannot_hide_explicit_failure_or_reference_another_page(): void
    {
        $recognizer = app(ReimbursementEvidenceRecognition::class);
        $result = $recognizer->normalize(['items' => [], 'skipped_pages' => [1], 'page_issues' => [['page' => 1, 'message' => '读取失败']]], [1], true);
        $this->assertSame('读取失败', $result['issues'][0]['message']);
        $this->expectException(ValidationException::class);
        $recognizer->normalize(['items' => [], 'skipped_pages' => [2], 'page_issues' => []], [1], true);
    }

    public function test_uncertain_non_cny_failed_or_refunded_payment_cannot_create_positive_expense(): void
    {
        foreach ([['uncertain' => true], ['currency' => 'USD'], ['kind' => 'receipt', 'status' => 'failed'], ['kind' => 'receipt', 'status' => 'refunded'], ['kind' => 'payment', 'status' => 'issued'], ['amount' => '0']] as $overrides) {
            $result = app(ReimbursementEvidenceRecognition::class)->normalize(['items' => [$this->candidate($overrides)], 'page_issues' => []], [1]);
            $this->assertNull($result['items'][0]['amount']);
            $this->assertNotEmpty($result['issues']);
        }
        $result = app(ReimbursementEvidenceRecognition::class)->normalize(['items' => [$this->candidate(['kind' => 'receipt', 'status' => 'paid'])], 'page_issues' => []], [1]);
        $this->assertNull($result['items'][0]['invoice_date']);
        $this->assertSame('2026-11-07', $result['items'][0]['payment_date']);
        $this->assertSame([], $result['issues']);
    }

    public function test_receipts_and_missing_numbers_do_not_add_speculative_duplicate_warnings(): void
    {
        foreach (['invoice', 'receipt', 'payment'] as $kind) {
            $result = app(ReimbursementEvidenceRecognition::class)->normalize(['items' => [$this->candidate(['kind' => $kind, 'status' => $kind === 'invoice' ? 'issued' : 'paid', 'number' => null])], 'page_issues' => []], [1]);
            $this->assertSame('101.00', $result['items'][0]['amount']);
            $this->assertSame([], $result['items'][0]['warnings']);
            $this->assertSame([], $result['issues']);
        }
        $result = app(ReimbursementEvidenceRecognition::class)->normalize(['items' => [$this->candidate(['kind' => 'receipt', 'warnings' => ['原件金额模糊']])], 'page_issues' => []], [1]);
        $this->assertSame(['原件金额模糊'], $result['items'][0]['warnings']);
        $this->assertNotEmpty($result['issues']);
    }

    public function test_evidence_start_is_owner_scoped_idempotent_and_does_not_submit(): void
    {
        Queue::fake();
        $user = $this->owner();
        $upload = ReimbursementUpload::factory()->create(['user_id' => $user->id]);
        $url = '/reimbursements/uploads/'.$upload->id.'/evidence';
        $this->actingAs($this->owner())->postJson($url)->assertNotFound();
        $this->actingAs($user)->postJson($url)->assertOk()->assertJsonPath('status', 'queued');
        $this->postJson($url)->assertOk();
        Queue::assertPushed(RecognizeReimbursementEvidence::class, 1);
        $this->getJson($url)->assertOk();
        $this->assertDatabaseCount('reimbursements', 0);
    }

    public function test_two_distinct_sources_can_share_one_original_but_not_the_same_candidate(): void
    {
        $user = $this->owner();
        $upload = ReimbursementUpload::factory()->create(['user_id' => $user->id]);
        $upload->forceFill(['evidence_result' => ['items' => [['id' => '1-1'], ['id' => '2-1']]]])->save();
        $items = array_map(fn ($id) => ['description' => '手写说明', 'amount' => '1.00', 'attachments' => [$upload->id], 'evidence_source' => ['upload_id' => $upload->id, 'candidate_id' => $id]], ['1-1', '2-1']);
        $draft = app(ReimbursementDraftService::class)->save($user, (string) Str::uuid(), ['revision' => 0, 'payload' => ['title' => '测试', 'type' => '其他', 'note' => '', 'cc' => [], 'items' => $items]], true);
        $this->assertCount(2, $draft->payload['items']);
        $this->assertDatabaseCount('reimbursement_uploads', 1);
        $url = '/reimbursements/drafts/'.$draft->id;
        $this->actingAs($user)->putJson($url, ['revision' => $draft->revision, 'payload' => $draft->payload])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'draft.payload.items');
        foreach ([[$items[0], $items[0]], [['attachments' => [$upload->id, $upload->id]]], [['attachments' => [$upload->id]], ['attachments' => [$upload->id]]], [[...$items[0], 'evidence_source' => ['upload_id' => $upload->id, 'candidate_id' => '9-9']]]] as $invalidItems) {
            $this->putJson($url, ['revision' => $draft->fresh()->revision, 'payload' => [...$draft->payload, 'items' => $invalidItems]])->assertUnprocessable();
        }
        $this->actingAs($this->owner())->putJson($url, ['revision' => $draft->fresh()->revision, 'payload' => $draft->payload])->assertForbidden();

        $this->expectException(ValidationException::class);
        app(ReimbursementEvidenceSources::class)->validate([$items[0], $items[0]]);
    }

    public function test_plain_duplicate_and_forged_source_still_fail(): void
    {
        $upload = ReimbursementUpload::factory()->create();
        foreach ([
            [['attachments' => [$upload->id]], ['attachments' => [$upload->id]]],
            [['attachments' => [$upload->id], 'evidence_source' => ['upload_id' => $upload->id, 'candidate_id' => '9-9']]],
        ] as $items) {
            try {
                app(ReimbursementEvidenceSources::class)->validate($items);
                $this->fail('Invalid source accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('items', $exception->errors());
            }
        }
    }

    public function test_review_is_owner_scoped_and_cannot_cancel_processing(): void
    {
        $owner = $this->owner();
        $upload = ReimbursementUpload::factory()->create(['user_id' => $owner->id]);
        $upload->forceFill(['evidence_status' => 'processing'])->save();
        $url = '/reimbursements/uploads/'.$upload->id.'/evidence/review';
        $this->actingAs($this->owner())->postJson($url)->assertNotFound();
        $this->actingAs($owner)->postJson($url)->assertConflict();
        $upload->forceFill(['evidence_status' => 'attention'])->save();
        $this->postJson($url)->assertOk()->assertJsonPath('status', 'reviewed');
    }

    public function test_local_preview_is_owner_only_and_does_not_clear_failure(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $file = StoredAttachment::factory()->create(['disk' => 'local', 'object_key' => 'evidence.pdf', 'mime_type' => 'application/pdf']);
        Storage::disk('local')->put('evidence.pdf', '%PDF-test');
        $upload = ReimbursementUpload::factory()->create(['user_id' => $owner->id, 'stored_attachment_id' => $file->id]);
        $upload->forceFill(['evidence_status' => 'attention'])->save();
        $url = '/reimbursements/uploads/'.$upload->id.'/preview';
        $this->actingAs($this->owner())->get($url)->assertNotFound();
        $this->actingAs($owner)->get($url)->assertOk()->assertStreamedContent('%PDF-test');
        $this->assertSame('attention', $upload->fresh()->evidence_status);
    }

    public function test_image_uses_aimon_agent_and_validates_multiticket_output(): void
    {
        Storage::fake('local');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aKAAAAABJRU5ErkJggg==');
        Storage::disk('local')->put('image.png', $bytes);
        $this->mock(InvoiceMemoryWorkspace::class)->shouldReceive('run')->once()->andReturnUsing(fn ($callback) => $callback(Storage::disk('local')->path('')));
        ReimbursementEvidenceAgent::fake([json_encode(['items' => [$this->candidate(), $this->candidate(['number' => '98765432', 'amount' => '20.00'])], 'page_issues' => []])]);
        $result = app(ReimbursementEvidenceRecognition::class)->recognize(new StoredAttachment(['disk' => 'local', 'object_key' => 'image.png', 'mime_type' => 'image/png', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]));
        $this->assertSame('completed', $result['status']);
        $this->assertCount(2, $result['items']);
        $this->assertSame([1], $result['done_pages']);
    }

    public function test_batch_image_uses_skip_instructions_and_finishes_without_candidates(): void
    {
        Storage::fake('local');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aKAAAAABJRU5ErkJggg==');
        Storage::disk('local')->put('image.png', $bytes);
        $this->mock(InvoiceMemoryWorkspace::class)->shouldReceive('run')->once()->andReturnUsing(fn ($callback) => $callback(Storage::disk('local')->path('')));
        ReimbursementEvidenceAgent::fake([json_encode(['items' => [], 'page_issues' => [], 'skipped_pages' => [1]])])->preventStrayPrompts();
        $result = app(ReimbursementEvidenceRecognition::class)->recognize(new StoredAttachment(['disk' => 'local', 'object_key' => 'image.png', 'mime_type' => 'image/png', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]), skipNonEvidence: true);
        $this->assertSame('completed', $result['status']);
        $this->assertSame([], $result['items']);
        $this->assertSame([], $result['issues']);
        $this->assertSame([1], $result['done_pages']);
        ReimbursementEvidenceAgent::assertPrompted(fn ($prompt) => $prompt->agent->skipNonEvidence && str_contains($prompt->agent->instructions(), 'skipped_pages'));
        $this->assertStringNotContainsString('skipped_pages', (new ReimbursementEvidenceAgent)->instructions());
    }

    public function test_chunk_job_persists_progress_and_continues_without_repeating_finished_work(): void
    {
        Queue::fake();
        $upload = ReimbursementUpload::factory()->create();
        $upload->forceFill(['evidence_status' => 'queued'])->save();
        $recognizer = $this->mock(ReimbursementEvidenceRecognition::class);
        $recognizer->shouldReceive('recognize')->once()->andReturn(['status' => 'processing', 'items' => [['id' => '1-1', 'amount' => '1.00']], 'issues' => [], 'done_pages' => [1, 2, 3, 4, 5], 'pages' => 6]);
        (new RecognizeReimbursementEvidence($upload->id))->handle($recognizer);
        $this->assertSame('processing', $upload->fresh()->evidence_status);
        $this->assertCount(5, $upload->fresh()->evidence_result['done_pages']);
        Queue::assertPushed(RecognizeReimbursementEvidence::class, 1);
        $upload->forceFill(['evidence_status' => 'completed'])->save();
        (new RecognizeReimbursementEvidence($upload->id))->handle($recognizer);
    }

    public function test_retry_only_requeues_failed_pages_and_keeps_existing_candidate_values(): void
    {
        Queue::fake();
        $owner = $this->owner();
        $upload = ReimbursementUpload::factory()->create(['user_id' => $owner->id]);
        $upload->forceFill(['evidence_status' => 'attention', 'evidence_result' => ['done_pages' => [1, 2], 'items' => [['id' => '1-1', 'amount' => '8.88']], 'issues' => [['page' => 2, 'message' => '模糊']]]])->save();
        $this->actingAs($owner)->postJson('/reimbursements/uploads/'.$upload->id.'/evidence', ['retry' => true])->assertOk();
        $this->assertSame([1], $upload->fresh()->evidence_result['done_pages']);
        $this->assertSame('8.88', $upload->fresh()->evidence_result['items'][0]['amount']);
    }

    public function test_private_samples_with_local_tools_when_explicitly_supplied(): void
    {
        $files = getenv('EVIDENCE_SAMPLE_FILES');
        if (! $files) {
            $this->markTestSkipped('Private local sample verification is opt-in.');
        }
        $workspace = new class extends InvoiceMemoryWorkspace
        {
            public function run(callable $callback): mixed
            {
                $dir = sys_get_temp_dir().'/evidence-test-'.Str::uuid();
                mkdir($dir, 0700);
                try {
                    return $callback($dir);
                } finally {
                    foreach (glob($dir.'/*') as $file) {
                        unlink($file);
                    } rmdir($dir);
                }
            }
        };
        $this->app->instance(InvoiceMemoryWorkspace::class, $workspace);
        foreach (json_decode($files, true, flags: JSON_THROW_ON_ERROR) as $file) {
            config(['filesystems.disks.sample' => ['driver' => 'local', 'root' => dirname($file), 'throw' => true]]);
            Storage::forgetDisk('sample');
            $attachment = new StoredAttachment(['disk' => 'sample', 'object_key' => basename($file), 'size' => filesize($file), 'sha256' => hash_file('sha256', $file), 'mime_type' => 'application/pdf']);
            $result = [];
            do {
                $result = app(ReimbursementEvidenceRecognition::class)->recognize($attachment, $result);
            } while ($result['status'] === 'processing');
            fwrite(STDERR, json_encode(['file' => basename($file), 'pages' => $result['pages'], 'candidates' => count($result['items']), 'recognized' => count(array_filter($result['items'], fn ($i) => $i['amount'] !== null)), 'issues' => $result['issues'], 'amounts' => array_map(fn ($item) => ['page' => $item['page'], 'region' => $item['region'], 'amount' => $item['amount'], 'kind' => $item['kind']], $result['items'])], JSON_UNESCAPED_UNICODE).PHP_EOL);
            $this->assertGreaterThan(0, $result['pages']);
        }
    }
}
