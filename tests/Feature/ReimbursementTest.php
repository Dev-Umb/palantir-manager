<?php

namespace Tests\Feature;

use App\Actions\ReimbursementDraftService;
use App\Jobs\SendReimbursementWechatNotice;
use App\Models\Permission;
use App\Models\ReimbursementBatch;
use App\Models\ReimbursementBatchFile;
use App\Models\ReimbursementDraft;
use App\Models\ReimbursementNotice;
use App\Models\ReimbursementUpload;
use App\Models\ReimbursementVersion;
use App\Models\ReimbursementWorkflow;
use App\Models\Role;
use App\Models\StoredAttachment;
use App\Models\User;
use App\Support\BusinessFileStorage;
use App\Support\InvoicePdfRecognition;
use App\Support\ReimbursementEvidenceRecognition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

class ReimbursementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $reviewer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['services.business_files.disk' => 'local']);
        $this->owner = $this->person(['submit']);
        $this->reviewer = $this->person();
        $this->admin = $this->person(['manage', 'configure']);
        $this->flow([[$this->reviewer->id]]);
    }

    public function test_pc_applicant_view_hides_oa_but_preserves_json_review_and_cc_contracts(): void
    {
        $input = $this->input();
        $input['cc'] = [$this->reviewer->id];
        $record = $this->actingAs($this->owner)->postJson('/reimbursements', $input)->assertCreated()->json('record');
        $this->get('/reimbursements?workspace=approval&scope=all')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Reimbursements/Index')->where('approval_ui', false)
            ->where('filters.workspace', 'application')->where('filters.scope', 'mine')->has('records.data', 1)
            ->where('nav', fn ($nav) => collect($nav)->firstWhere('key', 'reimbursement-approvals')['visible'] === false));
        $this->actingAs($this->reviewer)->get('/reimbursements?scope=cc')->assertOk()->assertInertia(fn ($page) => $page
            ->where('filters.scope', 'cc')->has('records.data', 1));
        $this->getJson('/reimbursements?scope=review')->assertOk()->assertJsonPath('records.data.0.id', $record['id']);
        $this->getJson('/reimbursements/versions/'.$record['id'])->assertJsonPath('record.can_decide', true);
        $this->get('/reimbursements/versions/'.$record['id'])->assertOk()->assertInertia(fn ($page) => $page->where('approval_ui', false));
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => '管理员']);
        $this->admin->roles()->attach($role);
        $this->actingAs($this->admin)->get('/reimbursements?workspace=approval')->assertOk()->assertInertia(fn ($page) => $page
            ->where('approval_ui', true)->where('filters.workspace', 'approval')->where('filters.scope', 'all')
            ->where('nav', fn ($nav) => collect($nav)->firstWhere('key', 'reimbursement-approvals')['visible'] === true));
    }

    public function test_pc_form_and_new_manual_draft_reuse_existing_authorization_and_revision_rules(): void
    {
        $this->actingAs($this->reviewer)->get('/reimbursements/create')->assertForbidden();
        $this->actingAs($this->owner)->get('/reimbursements/create')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Reimbursements/Form')->where('draft', null)->where('source', null)->has('types')->has('users'));
        $id = (string) Str::uuid();
        $payload = ['title' => 'PC草稿', 'type' => '其他', 'note' => '', 'items' => [], 'cc' => []];
        $data = ['id' => $id, 'revision' => 0, 'payload' => $payload];
        $this->postJson('/reimbursements/drafts', $data)->assertCreated()->assertJsonPath('draft.revision', 1);
        $this->postJson('/reimbursements/drafts', $data)->assertCreated()->assertJsonPath('draft.revision', 1);
        $this->get('/reimbursements/create?draft='.$id)->assertOk()->assertInertia(fn ($page) => $page->where('draft.id', $id));
        $this->actingAs($this->reviewer)->get('/reimbursements/create?draft='.$id)->assertForbidden();
        $other = $this->person(['submit']);
        $this->actingAs($other)->get('/reimbursements/create?draft='.$id)->assertForbidden();
        $this->actingAs($this->owner)->putJson('/reimbursements/drafts/'.$id, ['revision' => 1, 'payload' => [...$payload, 'note' => '小程序修改']])->assertOk();
        $this->putJson('/reimbursements/drafts/'.$id, ['revision' => 1, 'payload' => [...$payload, 'note' => 'PC旧修改']])->assertConflict();
        $this->get('/reimbursements/create?draft='.$id)->assertInertia(fn ($page) => $page->where('draft.payload.note', '小程序修改'));
        $this->assertSame(0, ReimbursementVersion::count());
        $batch = ReimbursementBatch::factory()->create(['user_id' => $this->owner->id]);
        ReimbursementDraft::whereKey($id)->update(['batch_id' => $batch->id]);
        $this->get('/reimbursements/create?draft='.$id)->assertConflict();
        $this->get('/reimbursements/drafts/'.$id)->assertOk();
    }

    public function test_pc_rejected_form_preserves_source_and_existing_non_admin_approval_flow(): void
    {
        $input = $this->input();
        $record = $this->actingAs($this->owner)->postJson('/reimbursements', $input)->assertCreated()->json('record');
        $url = '/reimbursements/create?version='.$record['id'];
        $this->get($url)->assertConflict();
        $this->actingAs($this->reviewer)->postJson($record['url'].'/decision', ['node_index' => 0, 'decision' => 'reject', 'note' => '补充说明'])->assertOk();
        $this->actingAs($this->owner)->get($url)->assertOk()->assertInertia(fn ($page) => $page
            ->component('Reimbursements/Form')->where('source.id', $record['id'])->where('source.can_resubmit', true)
            ->where('source.items.0.attachments.0.id', $input['items'][0]['attachments'][0]));
        $other = $this->person(['submit']);
        $this->actingAs($other)->get($url)->assertForbidden();
        $next = [...$input, 'submission_key' => (string) Str::uuid(), 'reimbursement_id' => $record['reimbursement_id'], 'expected_version' => 1];
        $this->actingAs($this->owner)->postJson('/reimbursements', $next)->assertCreated()->assertJsonPath('record.number', 2)->assertJsonPath('record.node_index', 0);
        $this->get($url)->assertConflict();
        $this->assertSame('rejected', ReimbursementVersion::findOrFail($record['id'])->status);
    }

    private function person(array $permissions = []): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'test-'.$user->id, 'label' => '测试']);
        foreach ($permissions as $action) {
            $permission = Permission::firstOrCreate(['key' => 'reimbursements.'.$action], ['module' => 'reimbursements', 'action' => $action, 'label' => $action]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);

        return $user;
    }

    public function test_draft_delete_is_owner_only_revision_checked_and_preserves_files_and_other_drafts(): void
    {
        $input = $this->input();
        $payload = array_intersect_key($input, array_flip(['title', 'type', 'note', 'items', 'cc']));
        $service = app(ReimbursementDraftService::class);
        $draft = $service->save($this->owner, (string) Str::uuid(), ['revision' => 0, 'payload' => $payload], true);
        $otherPayload = $payload;
        $otherPayload['items'][0]['attachments'] = [];
        $other = $service->save($this->owner, (string) Str::uuid(), ['revision' => 0, 'payload' => $otherPayload], true);
        $url = '/reimbursements/drafts/'.$draft->id;
        $this->actingAs($this->admin)->deleteJson($url, ['revision' => 1])->assertForbidden();
        $this->actingAs($this->owner)->deleteJson($url, ['revision' => 2])->assertConflict();
        $this->deleteJson($url, [])->assertUnprocessable();
        $this->getJson($url)->assertOk();
        $this->deleteJson($url, ['revision' => 1])->assertOk();
        $this->assertSoftDeleted('reimbursement_drafts', ['id' => $draft->id]);
        $this->getJson($url)->assertNotFound();
        $this->deleteJson($url, ['revision' => 1])->assertNotFound();
        $this->putJson($url, ['revision' => 1, 'payload' => $payload])->assertConflict();
        $this->postJson('/reimbursements', [...$input, 'draft_id' => $draft->id, 'draft_revision' => 1])->assertNotFound();
        $context = ['id' => $draft->id, 'revision' => 0, 'operation' => (string) Str::uuid(), 'item' => 0, 'payload' => $otherPayload];
        $this->postJson('/reimbursements/uploads', ['draft_context' => json_encode($context), 'file' => UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\ninvoice")])->assertConflict();
        $this->getJson('/reimbursements/drafts')->assertJsonCount(1, 'records.data')->assertJsonPath('records.data.0.id', $other->id);
        $this->putJson('/reimbursements/drafts/'.$other->id, ['revision' => 1, 'payload' => $otherPayload])->assertOk();
        $this->assertSame(1, ReimbursementUpload::count());
        $file = StoredAttachment::findOrFail(ReimbursementUpload::firstOrFail()->stored_attachment_id);
        Storage::disk($file->disk)->assertExists($file->object_key);
        $this->assertSame(0, ReimbursementVersion::count());
    }

    public function test_pdf_upload_atomically_saves_incomplete_draft_and_retry_does_not_duplicate(): void
    {
        $id = (string) Str::uuid();
        $context = ['id' => $id, 'operation' => (string) Str::uuid(), 'revision' => 0, 'item' => 0, 'payload' => ['title' => '', 'type' => '其他', 'note' => '', 'cc' => [], 'items' => [['description' => '', 'amount' => '1.', 'invoice_date' => null, 'attachments' => []]]]];
        $upload = fn () => $this->actingAs($this->owner)->postJson('/reimbursements/uploads', ['draft_context' => json_encode($context), 'file' => UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\ninvoice")]);
        $first = $upload()->assertCreated()->json();
        $this->assertSame($id, $first['draft']['id']);
        $this->assertSame('1.', $first['draft']['payload']['items'][0]['amount']);
        $upload()->assertCreated()->assertJsonPath('file.id', $first['file']['id']);
        $this->assertSame(1, ReimbursementUpload::count());
        $this->assertSame(0, ReimbursementVersion::count());
        $this->assertSame(0, ReimbursementNotice::count());
        $this->actingAs($this->owner)->getJson('/reimbursements/drafts')->assertJsonCount(1, 'records.data');
        $this->getJson('/reimbursements')->assertJsonPath('pending_count', 0);
        $this->get('/reimbursements/drafts/'.$id.'/files/'.$first['file']['id'])->assertOk();
        $this->actingAs($this->admin)->getJson('/reimbursements/drafts/'.$id)->assertForbidden();
        $this->get('/reimbursements/drafts/'.$id.'/files/'.$first['file']['id'])->assertForbidden();
        $this->getJson('/reimbursements/drafts')->assertJsonCount(0, 'records.data');
    }

    public function test_draft_revision_preserves_newer_edits_and_submission_consumes_only_after_success(): void
    {
        $input = $this->input();
        $payload = array_intersect_key($input, array_flip(['title', 'type', 'note', 'items', 'cc']));
        $id = (string) Str::uuid();
        $service = app(ReimbursementDraftService::class);
        $draft = $service->save($this->owner, $id, ['revision' => 0, 'payload' => $payload], true);
        $payload['title'] = '新标题';
        $this->actingAs($this->owner)->putJson('/reimbursements/drafts/'.$id, ['revision' => 1, 'payload' => $payload])->assertOk()->assertJsonPath('draft.revision', 2);
        $this->putJson('/reimbursements/drafts/'.$id, ['revision' => 1, 'payload' => $payload])->assertOk()->assertJsonPath('draft.revision', 2);
        $older = $payload;
        $older['title'] = '旧页面';
        $this->putJson('/reimbursements/drafts/'.$id, ['revision' => 1, 'payload' => $older])->assertConflict();
        $this->actingAs($this->reviewer)->putJson('/reimbursements/drafts/'.$id, ['revision' => 2, 'payload' => $older])->assertForbidden();
        $input = [...$input, 'title' => '新标题', 'draft_id' => $id, 'draft_revision' => 2];
        $this->actingAs($this->owner)->postJson('/reimbursements', [...$input, 'note' => ''])->assertUnprocessable();
        $this->getJson('/reimbursements/drafts/'.$id)->assertOk();
        $record = $this->submit($input);
        $this->actingAs($this->owner)->postJson('/reimbursements', $input)->assertCreated()->assertJsonPath('record.id', $record['id']);
        $this->getJson('/reimbursements/drafts')->assertJsonCount(0, 'records.data');
        $this->putJson('/reimbursements/drafts/'.$id, ['revision' => 2, 'payload' => $payload])->assertConflict();
        $this->deleteJson('/reimbursements/drafts/'.$id, ['revision' => 2])->assertConflict();
        $this->assertSame(1, ReimbursementVersion::count());
    }

    public function test_drafts_reject_foreign_attachments_and_preserve_multiple_drafts(): void
    {
        $input = $this->input();
        $payload = array_intersect_key($input, array_flip(['title', 'type', 'note', 'items', 'cc']));
        $service = app(ReimbursementDraftService::class);
        $first = $service->save($this->owner, (string) Str::uuid(), ['revision' => 0, 'payload' => $payload], true);
        $payload['items'][0]['attachments'] = [];
        $second = $service->save($this->owner, (string) Str::uuid(), ['revision' => 0, 'payload' => $payload], true);
        $payload['items'][0]['attachments'] = $input['items'][0]['attachments'];
        $this->actingAs($this->owner)->putJson('/reimbursements/drafts/'.$second->id, ['revision' => 1, 'payload' => $payload])->assertUnprocessable();
        $this->getJson('/reimbursements/drafts')->assertJsonCount(2, 'records.data');
        $this->postJson('/reimbursements', $input)->assertUnprocessable();
    }

    public function test_draft_upload_conflict_rolls_back_and_rejected_versions_can_be_drafted_again(): void
    {
        $input = $this->input();
        $payload = array_intersect_key($input, array_flip(['title', 'type', 'note', 'items', 'cc']));
        $service = app(ReimbursementDraftService::class);
        $id = (string) Str::uuid();
        $service->save($this->owner, $id, ['revision' => 0, 'payload' => $payload], true);
        $context = ['id' => $id, 'revision' => 0, 'operation' => (string) Str::uuid(), 'item' => 0, 'payload' => $payload];
        $this->actingAs($this->owner)->postJson('/reimbursements/uploads', ['draft_context' => json_encode($context), 'file' => UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\ninvoice")])->assertConflict();
        $this->assertSame(1, ReimbursementUpload::count());
        $record = $this->submit([...$input, 'draft_id' => $id, 'draft_revision' => 1]);
        $this->decision($this->reviewer, $record, 'reject', note: '补充')->assertOk();
        $source = ['reimbursement_id' => $record['reimbursement_id'], 'expected_version' => 1];
        $next = $service->save($this->owner, (string) Str::uuid(), ['revision' => 0, 'payload' => [...$payload, ...$source]], true);
        $this->actingAs($this->owner)->getJson('/reimbursements/drafts/'.$next->id)->assertOk()->assertJsonPath('draft.source_version_id', $record['id']);
        $result = $this->submit([...$input, ...$source, 'submission_key' => (string) Str::uuid(), 'draft_id' => $next->id, 'draft_revision' => 1]);
        $this->assertSame(2, $result['number']);
        $this->assertSame($record['items'][0]['attachments'][0]['id'], $result['items'][0]['attachments'][0]['id']);
    }

    public function test_invoice_date_is_validated_and_preserved_with_historical_attachments(): void
    {
        $input = $this->input();
        foreach (['2026-02-30', '29/10/2026', '2026-1-01', 'invalid'] as $date) {
            $input['items'][0]['invoice_date'] = $date;
            $this->actingAs($this->owner)->postJson('/reimbursements', $input)->assertUnprocessable()->assertJsonValidationErrors('items.0.invoice_date');
        }
        $input['items'][0]['invoice_date'] = '2026-10-29';
        $extra = $this->input();
        $input['items'][0]['attachments'][] = $extra['items'][0]['attachments'][0];
        $record = $this->submit($input);
        $this->assertSame('2026-10-29', $record['items'][0]['invoice_date']);
        $this->assertCount(2, $record['items'][0]['attachments']);
        $this->decision($this->reviewer, $record, 'reject', note: '补充说明')->assertOk();
        $input = [...$input, 'submission_key' => (string) Str::uuid(), 'reimbursement_id' => $record['reimbursement_id'], 'expected_version' => 1];
        $input['items'][0]['invoice_date'] = '2026-10-28';
        $next = $this->submit($input);
        $this->assertSame('2026-10-28', $next['items'][0]['invoice_date']);
        $this->assertCount(2, $next['items'][0]['attachments']);
        $this->assertSame(10, $next['items'][0]['cents']);
        $this->actingAs($this->owner)->getJson($record['url'])->assertJsonPath('record.items.0.invoice_date', '2026-10-29');
        $legacy = $this->submit($this->input());
        $this->assertNull($legacy['items'][0]['invoice_date']);
    }

    public function test_upload_timing_contains_only_stage_metrics_and_keeps_private_file(): void
    {
        Log::spy();
        $response = $this->actingAs($this->owner)->postJson('/reimbursements/uploads', ['file' => UploadedFile::fake()->image('private-invoice.png')])->assertCreated();
        $timing = $response->json('timing');
        $this->assertEqualsCanonicalizing(['trace', 'server_ms', 'storage_write', 'storage_verify'], array_keys($timing));
        $this->assertTrue(Str::isUuid($timing['trace']));
        $this->assertGreaterThanOrEqual(0, $timing['storage_write']);
        $this->assertGreaterThanOrEqual(0, $timing['storage_verify']);
        Log::shouldHaveReceived('info')->withArgs(function ($message, $context): bool {
            $this->assertSame(['trace', 'stage', 'bytes', 'duration_ms', 'success'], array_keys($context));

            return $message === 'reimbursement.invoice_timing' && $context['success'];
        })->twice();
        $stored = StoredAttachment::firstOrFail();
        $this->assertSame(hash('sha256', Storage::disk('local')->get($stored->object_key)), $stored->sha256);
        $this->assertDatabaseHas('reimbursement_uploads', ['id' => $response->json('file.id'), 'user_id' => $this->owner->id]);
    }

    public function test_administrator_inbox_filters_before_pagination_without_changing_review_scope(): void
    {
        $this->admin->roles()->attach(Role::firstOrCreate(['name' => 'admin'], ['label' => '管理员']));
        $flow = ['nodes' => [['name' => '审批', 'mode' => 'all', 'people' => [['id' => $this->admin->id, 'name' => '管理员'], ['id' => $this->reviewer->id, 'name' => '审批人']]]], 'cc' => [], 'triggers' => []];
        $make = function (array $attributes = []) use ($flow): ReimbursementVersion {
            $version = ReimbursementVersion::factory()->create([...['status' => 'pending', 'flow' => $flow], ...$attributes]);
            DB::table('reimbursement_access')->insert(['version_id' => $version->id, 'user_id' => $this->admin->id, 'kind' => 'approver']);

            return $version;
        };
        $eligible = collect(range(1, 21))->map(fn () => $make());
        $make(['decisions' => [['node' => 0, 'user_id' => $this->admin->id, 'name' => '管理员', 'decision' => 'approve', 'note' => '']]]);
        $make(['flow' => [...$flow, 'nodes' => [['name' => '前置', 'mode' => 'any', 'people' => [['id' => $this->reviewer->id, 'name' => '审批人']]], ...$flow['nodes']]]]);
        $make(['status' => 'approved']);
        $historical = $make();
        DB::table('reimbursements')->where('id', $historical->reimbursement_id)->update(['latest_version' => 2]);
        $deleted = $this->person();
        $make(['flow' => [...$flow, 'nodes' => [['name' => '失效会签', 'mode' => 'all', 'people' => [['id' => $this->admin->id, 'name' => '管理员'], ['id' => $deleted->id, 'name' => '失效人员']]]]]]);
        $deleted->delete();
        $this->actingAs($this->admin)->getJson('/reimbursements/approvals')->assertOk()->assertJsonPath('records.total', 21)->assertJsonCount(20, 'records.data')->assertJsonPath('records.data.0.id', $eligible->last()->id);
        $this->getJson('/reimbursements/approvals?page=2')->assertOk()->assertJsonCount(1, 'records.data')->assertJsonPath('records.data.0.id', $eligible->first()->id);
        $this->getJson('/reimbursements?scope=review&status=pending')->assertOk()->assertJsonPath('records.total', 24)->assertJsonPath('pending_count', 24);
        $this->getJson('/reimbursements/approvals?page=0')->assertUnprocessable();
        $this->actingAs($this->reviewer)->getJson('/reimbursements/approvals')->assertForbidden();
        $this->actingAs($this->owner)->getJson('/reimbursements?scope=mine')->assertOk();
    }

    public function test_admin_inbox_tracks_decisions_and_preserves_assigned_non_admin_pc_approval(): void
    {
        $this->admin->roles()->attach(Role::firstOrCreate(['name' => 'admin'], ['label' => '管理员']));
        $this->flow([[$this->admin->id, $this->reviewer->id]], 'all');
        $record = $this->submit($this->input());
        $this->actingAs($this->admin)->getJson('/reimbursements/approvals')->assertJsonPath('records.total', 1);
        $this->decision($this->admin, $record, 'reject')->assertUnprocessable();
        $this->decision($this->admin, $record)->assertOk()->assertJsonPath('record.status', 'pending');
        $this->getJson('/reimbursements/approvals')->assertJsonPath('records.total', 0);
        $this->decision($this->admin, $record)->assertForbidden();
        $this->decision($this->reviewer, $record)->assertOk()->assertJsonPath('record.status', 'approved');
        $this->flow([[$this->reviewer->id]]);
        $other = $this->submit($this->input());
        $this->actingAs($this->admin)->getJson('/reimbursements/approvals')->assertJsonPath('records.total', 0);
        $this->decision($this->admin, $other)->assertForbidden();
        $this->admin->roles()->attach(Role::firstOrCreate(['name' => 'timebook_operator'], ['label' => '工日簿']));
        $this->actingAs($this->admin->fresh())->getJson('/reimbursements/approvals')->assertForbidden();
    }

    public function test_invoice_recognition_is_owner_scoped_cached_and_does_not_submit(): void
    {
        $input = $this->input();
        $upload = $input['items'][0]['attachments'][0];
        $result = ['status' => 'recognized', 'fields' => ['total_cents' => 11300], 'warnings' => []];
        $this->mock(InvoicePdfRecognition::class)->shouldReceive('recognize')->once()->andReturn($result);
        $this->actingAs($this->reviewer)->postJson('/reimbursements/uploads/'.$upload.'/recognize')->assertForbidden();
        $other = $this->person(['submit']);
        $this->actingAs($other)->postJson('/reimbursements/uploads/'.$upload.'/recognize')->assertNotFound();
        $this->actingAs($this->owner)->postJson('/reimbursements/uploads/'.$upload.'/recognize')->assertOk()->assertJsonPath('fields.total_cents', 11300);
        $this->postJson('/reimbursements/uploads/'.$upload.'/recognize')->assertOk();
        $this->assertDatabaseCount('reimbursement_versions', 0);
        $this->assertDatabaseHas('reimbursement_uploads', ['id' => $upload]);
    }

    public function test_invoice_recognition_failure_keeps_attachment_and_manual_submission(): void
    {
        $input = $this->input();
        $upload = $input['items'][0]['attachments'][0];
        $this->mock(InvoicePdfRecognition::class)->shouldReceive('recognize')->once()->andThrow(new \RuntimeException('timeout'));
        $this->actingAs($this->owner)->postJson('/reimbursements/uploads/'.$upload.'/recognize')->assertUnprocessable();
        $this->postJson('/reimbursements', $input)->assertCreated();
        $this->assertDatabaseHas('reimbursement_uploads', ['id' => $upload]);
    }

    private function flow(array $groups, string $mode = 'any', array $cc = [], array $triggers = []): void
    {
        ReimbursementWorkflow::findOrFail(1)->update(['definition' => ['nodes' => array_map(fn ($ids) => ['name' => '审批', 'mode' => $mode, 'people' => $ids], $groups), 'cc' => $cc, 'triggers' => $triggers]]);
    }

    public function test_evidence_failures_block_submission_until_explicit_manual_resolution(): void
    {
        $input = $this->input();
        $id = $input['items'][0]['attachments'][0];
        foreach (['queued', 'processing', 'attention', 'failed'] as $status) {
            ReimbursementUpload::findOrFail($id)->forceFill(['evidence_status' => $status])->save();
            $this->actingAs($this->owner)->postJson('/reimbursements', $input)->assertUnprocessable();
        }
        $this->postJson('/reimbursements/uploads/'.$id.'/evidence/review')->assertOk();
        $this->postJson('/reimbursements', $input)->assertCreated();
        $this->postJson('/reimbursements/uploads/'.$id.'/evidence/review')->assertConflict();
    }

    public function test_multi_ticket_original_submits_once_and_retains_manual_values_and_sources(): void
    {
        $input = $this->input();
        $id = $input['items'][0]['attachments'][0];
        ReimbursementUpload::findOrFail($id)->forceFill(['evidence_status' => 'completed', 'evidence_result' => ['items' => [['id' => '1-1'], ['id' => '2-1']]]])->save();
        $input['items'] = array_map(fn ($candidate) => [...$input['items'][0], 'description' => '手工核对', 'amount' => '8.88', 'evidence_source' => ['upload_id' => $id, 'candidate_id' => $candidate]], ['1-1', '2-1']);
        $result = $this->actingAs($this->owner)->postJson('/reimbursements', $input)->assertCreated();
        $result->assertJsonPath('record.items.0.amount', '8.88')->assertJsonPath('record.items.1.evidence_source.candidate_id', '2-1');
        $response = $this->get($result->json('record.zip_url'))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertSame(1, $zip->numFiles);
        $zip->close();
        unlink($path);
        $this->postJson('/reimbursements', $input)->assertCreated();
        $this->assertDatabaseCount('reimbursements', 1);
    }

    public function test_lodging_ai_amount_can_be_overridden_saved_reopened_and_submitted_above_nightly_cap(): void
    {
        $input = $this->input();
        $input['type'] = '住宿费';
        $upload = ReimbursementUpload::findOrFail($input['items'][0]['attachments'][0]);
        $result = app(ReimbursementEvidenceRecognition::class)->normalize(['items' => [['page' => 1, 'region' => '整页', 'type' => '住宿费', 'kind' => 'invoice', 'status' => 'issued', 'currency' => 'CNY', 'amount' => '450.00', 'lodging_nights' => 3, 'date' => null, 'number' => 'lodging-1', 'description' => '住宿', 'uncertain' => false, 'warnings' => []]], 'page_issues' => []], [1]);
        $source = $result['items'][0];
        $this->assertSame('300.00', $source['amount']);
        $upload->forceFill(['evidence_status' => 'completed', 'evidence_result' => $result])->save();
        $input['items'][0] = [...$input['items'][0], 'description' => $source['description'], 'amount' => $source['amount'], 'evidence_source' => ['upload_id' => $upload->id, 'candidate_id' => $source['id']]];
        $payload = array_intersect_key($input, array_flip(['title', 'type', 'note', 'items', 'cc']));
        $draft = app(ReimbursementDraftService::class)->save($this->owner, (string) Str::uuid(), ['revision' => 0, 'payload' => $payload], true);
        $url = '/reimbursements/drafts/'.$draft->id;
        foreach (['90.00', '450.00'] as $manual) {
            $payload['items'][0]['amount'] = $manual;
            $this->actingAs($this->owner)->putJson($url, ['revision' => $draft->fresh()->revision, 'payload' => $payload])->assertOk();
            $this->getJson($url)->assertOk()->assertJsonPath('draft.payload.items.0.amount', $manual);
        }
        $record = $this->submit([...$input, 'items' => $payload['items'], 'draft_id' => $draft->id, 'draft_revision' => $draft->fresh()->revision]);
        $this->assertSame('450.00', $record['items'][0]['amount']);
        $this->assertSame('450.00', $upload->fresh()->evidence_result['items'][0]['original_amount']);
    }

    private function input(?User $owner = null): array
    {
        $owner ??= $this->owner;
        $stored = app(BusinessFileStorage::class)->storeBytes('attachments/'.Str::uuid().'.pdf', "%PDF-1.4\n测试发票", '中文发票.pdf', 'application/pdf');
        $upload = ReimbursementUpload::create(['user_id' => $owner->id, 'stored_attachment_id' => $stored->id]);

        return ['submission_key' => (string) Str::uuid(), 'title' => '客户拜访', 'type' => '差旅交通', 'note' => '出差费用', 'items' => [['description' => '车票', 'amount' => '0.10', 'attachments' => [$upload->id]]], 'cc' => []];
    }

    private function submit(array $input): array
    {
        return $this->actingAs($this->owner)->postJson('/reimbursements', $input)->assertCreated()->json('record');
    }

    private function decision(User $actor, array $record, string $decision = 'approve', int $node = 0, string $note = ''): TestResponse
    {
        return $this->actingAs($actor)->postJson($record['url'].'/decision', ['decision' => $decision, 'node_index' => $node, 'note' => $note]);
    }

    public function test_batch_review_blocks_submission_but_confirmed_new_type_uses_existing_flow(): void
    {
        $input = $this->input();
        $input['type'] = '住宿费';
        $batch = ReimbursementBatch::factory()->create(['user_id' => $this->owner->id]);
        $file = ReimbursementBatchFile::factory()->create(['batch_id' => $batch->id, 'upload_id' => $input['items'][0]['attachments'][0], 'status' => 'recognized', 'reviewed' => false]);
        $this->actingAs($this->owner)->postJson('/reimbursements', $input)->assertUnprocessable();
        $this->assertDatabaseCount('reimbursement_versions', 0);
        $file->update(['reviewed' => true]);
        $record = $this->submit($input);
        $this->assertSame('住宿费', $record['type']);
        $this->assertSame($this->reviewer->id, $record['flow']['nodes'][0]['people'][0]['id']);
    }

    public function test_money_idempotency_upload_ownership_and_adjacent_permissions(): void
    {
        $input = $this->input();
        $second = $this->input()['items'][0];
        $second['amount'] = '0.20';
        $input['items'][] = $second;
        $input['total_cents'] = 1;
        $record = $this->submit($input);
        $this->assertSame(30, $record['total_cents']);
        $this->assertSame($record['id'], $this->submit($input)['id']);
        $this->assertSame(1, ReimbursementVersion::count());
        $this->postJson('/reimbursements', [...$input, 'title' => '试图覆盖'])->assertConflict();
        $this->actingAs($this->reviewer)->postJson('/reimbursements', $this->input())->assertForbidden();
        $this->actingAs($this->owner)->postJson('/reimbursements', $this->input($this->reviewer))->assertUnprocessable();
        foreach (['0', '-1', '0.001', '1e2'] as $amount) {
            $bad = $this->input();
            $bad['items'][0]['amount'] = $amount;
            $this->postJson('/reimbursements', $bad)->assertUnprocessable();
        }
        $this->getJson('/reimbursements/workflow')->assertForbidden();
        $this->getJson('/reimbursements?scope=all')->assertForbidden();
        $this->assertFalse($this->owner->canDo('projects.manage'));
    }

    public function test_sequential_countersign_permissions_duplicate_and_final_notification(): void
    {
        $other = $this->person();
        $last = $this->person();
        $this->flow([[$this->reviewer->id, $other->id], [$last->id]], 'all');
        $record = $this->submit($this->input());
        $this->decision($this->admin, $record)->assertForbidden();
        $this->decision($last, $record)->assertForbidden();
        $this->decision($this->reviewer, $record)->assertOk()->assertJsonPath('record.node_index', 0);
        $this->decision($this->reviewer, $record)->assertForbidden();
        $this->assertSame(0, ReimbursementNotice::count());
        $this->decision($other, $record)->assertOk()->assertJsonPath('record.node_index', 1);
        $this->decision($last, $record, node: 0)->assertConflict();
        $this->decision($last, $record, node: 1)->assertOk()->assertJsonPath('record.status', 'approved');
        $this->decision($last, $record, node: 1)->assertConflict();
        $this->assertSame(1, ReimbursementNotice::where('event', 'approved')->count());
    }

    public function test_rejection_resubmit_snapshots_and_version_cc_isolation(): void
    {
        $oldCc = $this->person();
        $newCc = $this->person();
        $this->flow([[$this->reviewer->id]], cc: [$oldCc->id], triggers: ['rejected']);
        $input = $this->input();
        $record = $this->submit($input);
        $this->actingAs($oldCc)->getJson($record['url'])->assertForbidden();
        $this->decision($this->reviewer, $record, 'reject')->assertUnprocessable();
        $this->decision($this->reviewer, $record, 'reject', note: '补充说明')->assertOk();
        $this->actingAs($oldCc)->getJson($record['url'])->assertOk();
        $this->flow([[$this->reviewer->id]], cc: [$newCc->id], triggers: ['submitted']);
        $input = [...$input, 'submission_key' => (string) Str::uuid(), 'reimbursement_id' => $record['reimbursement_id'], 'expected_version' => 1, 'note' => '补全说明'];
        $next = $this->submit($input);
        $this->assertSame(2, $next['number']);
        $this->assertSame(0, $next['node_index']);
        $this->assertSame([], $next['decisions']);
        $this->actingAs($oldCc)->getJson($next['url'])->assertForbidden();
        $this->actingAs($newCc)->getJson($record['url'])->assertForbidden();
        $this->getJson($next['url'])->assertOk()->assertJsonCount(1, 'record.versions');
        $this->decision($newCc, $next)->assertForbidden();
        $this->actingAs($this->owner)->getJson($record['url'])->assertJsonPath('record.note', '出差费用')->assertJsonPath('record.can_resubmit', false);
    }

    public function test_or_sign_snapshot_and_invalid_reviewer_never_skipped(): void
    {
        $other = $this->person();
        $this->flow([[$this->reviewer->id, $other->id]]);
        $record = $this->submit($this->input());
        $this->flow([[$this->admin->id]]);
        $this->decision($other, $record)->assertOk()->assertJsonPath('record.status', 'approved');
        $record = $this->submit($this->input());
        $this->admin->delete();
        $this->actingAs($this->owner)->getJson($record['url'])->assertOk()->assertJsonPath('record.blocked', true);
        $this->postJson('/reimbursements', $this->input())->assertUnprocessable();
    }

    public function test_invoice_preview_zip_chinese_collisions_and_access(): void
    {
        $input = $this->input();
        $input['items'][] = $this->input()['items'][0];
        $record = $this->submit($input);
        $file = $record['items'][0]['attachments'][0];
        $this->get($file['preview_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $response = $this->get($record['zip_url'])->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertSame(2, $zip->numFiles);
        $this->assertSame('01-01_中文发票.pdf', $zip->getNameIndex(0));
        $this->assertSame('02-01_中文发票.pdf', $zip->getNameIndex(1));
        $zip->close();
        unlink($path);
        $outsider = $this->person();
        $this->actingAs($outsider)->get($file['download_url'])->assertForbidden();
        $this->get($record['zip_url'])->assertForbidden();
        $this->getJson($record['url'])->assertForbidden();
        $this->actingAs($this->owner);
        Storage::disk('local')->delete(StoredAttachment::first()->object_key);
        $this->getJson($record['zip_url'])->assertUnprocessable()->assertJsonPath('message', '发票缺失、读取失败或压缩包无法完整生成，请重试或联系管理员。');
    }

    public function test_filter_upload_validation_and_workflow_revision(): void
    {
        $record = $this->submit($this->input());
        $this->getJson('/reimbursements?'.http_build_query(['type' => '办公采购']))->assertOk()->assertJsonCount(0, 'records.data');
        $this->getJson('/reimbursements?status=pending')->assertOk()->assertJsonCount(1, 'records.data')->assertJsonPath('pending_count', 1);
        $this->getJson('/reimbursements?status=approved')->assertOk()->assertJsonCount(0, 'records.data')->assertJsonPath('pending_count', 1);
        $this->getJson('/reimbursements?owner=nonexistent')->assertJsonPath('pending_count', 0);
        $this->actingAs($this->reviewer)->getJson('/reimbursements?scope=mine')->assertJsonPath('pending_count', 0);
        $this->actingAs($this->owner);
        $temp = tempnam(sys_get_temp_dir(), 'fake-invoice');
        file_put_contents($temp, '<html>bad</html>');
        $this->postJson('/reimbursements/uploads', ['file' => new UploadedFile($temp, 'fake.pdf', null, null, true)])->assertUnprocessable();
        unlink($temp);
        $this->postJson('/reimbursements/uploads', ['file' => UploadedFile::fake()->image('发票.png')])->assertCreated();
        $this->getJson('/reimbursements?to='.now()->format('Y-m-d'))->assertOk()->assertJsonCount(1, 'records.data');
        $definition = ReimbursementWorkflow::find(1)->definition;
        $this->actingAs($this->admin)->putJson('/reimbursements/workflow', ['revision' => 0, ...$definition])->assertOk();
        $this->putJson('/reimbursements/workflow', ['revision' => 0, ...$definition])->assertConflict();
        $this->putJson('/reimbursements/workflow', ['revision' => 1, ...$definition, 'nodes' => 'bad'])->assertUnprocessable();
        $this->getJson('/reimbursements?owner=nonexistent')->assertOk()->assertJsonCount(0, 'records.data');
        $this->actingAs($this->owner)->getJson($record['url'])->assertJsonPath('record.flow.nodes.0.people.0.id', $this->reviewer->id);
    }

    public function test_wechat_delivery_deduplicates_and_preserves_business_result_on_denial(): void
    {
        config(['wechat.appid' => 'test-app', 'wechat.secret' => 'test-secret', 'reimbursements.wechat_template' => 'test-template']);
        $binding = (string) Str::uuid();
        DB::table('wechat_account_bindings')->insert(['user_id' => $this->owner->id, 'appid' => 'test-app', 'openid' => 'test-openid', 'version' => $binding]);
        $record = $this->submit($this->input());
        ReimbursementVersion::findOrFail($record['id'])->update(['wechat_subscribed' => true, 'wechat_binding_version' => $binding]);
        $this->decision($this->reviewer, $record)->assertOk();
        $notice = ReimbursementNotice::where('event', 'approved')->firstOrFail();
        Http::fake(['*/cgi-bin/token*' => Http::response(['access_token' => 'fake-token']), '*/subscribe/send*' => Http::response(['errcode' => 43101])]);
        $job = new SendReimbursementWechatNotice($notice->id);
        $this->assertSame('integrations', $job->queue);
        $job->handle();
        $job->handle();
        $this->assertSame('denied', $notice->fresh()->delivery_status);
        $this->assertSame('approved', ReimbursementVersion::find($record['id'])->status);
        Http::assertSentCount(2);
        $this->actingAs($this->owner)->getJson('/reimbursements/notices?unread=1')->assertOk()->assertJsonCount(1, 'notices.data');
        $this->getJson($record['url'])->assertOk();
        $this->getJson('/reimbursements/notices?unread=1')->assertJsonCount(0, 'notices.data');
        $this->actingAs($this->reviewer)->patchJson('/reimbursements/notices/'.$notice->id.'/read')->assertForbidden();
    }

    public function test_wechat_success_and_binding_replacement_do_not_send_to_wrong_person(): void
    {
        config(['wechat.appid' => 'test-app', 'wechat.secret' => 'test-secret', 'reimbursements.wechat_template' => 'test-template']);
        Cache::flush();
        $binding = (string) Str::uuid();
        DB::table('wechat_account_bindings')->insert(['user_id' => $this->owner->id, 'appid' => 'test-app', 'openid' => 'test-openid', 'version' => $binding]);
        $record = $this->submit($this->input());
        ReimbursementVersion::findOrFail($record['id'])->update(['wechat_subscribed' => true, 'wechat_binding_version' => $binding]);
        $this->decision($this->reviewer, $record)->assertOk();
        $notice = ReimbursementNotice::firstOrFail();
        Http::fake(['*/cgi-bin/token*' => Http::response(['access_token' => 'fake-token']), '*/subscribe/send*' => Http::response(['errcode' => 0])]);
        (new SendReimbursementWechatNotice($notice->id))->handle();
        $this->assertSame('sent', $notice->fresh()->delivery_status);
        $notice->refresh()->update(['delivery_status' => 'pending']);
        DB::table('wechat_account_bindings')->where('user_id', $this->owner->id)->update(['version' => (string) Str::uuid()]);
        (new SendReimbursementWechatNotice($notice->id))->handle();
        $this->assertSame('unavailable', $notice->fresh()->delivery_status);
        Http::assertSentCount(2);
    }

    public function test_timebook_role_stays_restricted_and_permission_install_is_additive(): void
    {
        $role = Role::create(['name' => 'timebook_operator', 'label' => '工日簿']);
        $this->owner->roles()->attach($role);
        $this->actingAs($this->owner->fresh())->getJson('/reimbursements')->assertForbidden();
        $this->getJson('/reimbursements/options')->assertForbidden();
        $business = Role::create(['name' => 'business', 'label' => '业务']);
        $existing = Permission::create(['key' => 'dashboard.view', 'module' => 'dashboard', 'action' => 'view', 'label' => '工作台']);
        $business->permissions()->attach($existing);
        $this->artisan('reimbursements:install-permissions')->assertSuccessful();
        $this->artisan('reimbursements:install-permissions')->assertSuccessful();
        $this->assertSame(['dashboard.view', 'reimbursements.submit'], $business->permissions()->orderBy('key')->pluck('key')->all());
    }

    public function test_unread_notice_pagination_preserves_filter_and_account_scope(): void
    {
        $record = $this->submit($this->input());
        foreach (range(1, 51) as $i) {
            ReimbursementNotice::factory()->create(['version_id' => $record['id'], 'user_id' => $this->owner->id, 'event' => 'cc:'.$i]);
        }
        ReimbursementNotice::factory()->create(['version_id' => $record['id'], 'user_id' => $this->owner->id, 'event' => 'read', 'read_at' => now()]);
        ReimbursementNotice::factory()->create(['version_id' => $record['id'], 'user_id' => $this->reviewer->id, 'event' => 'other']);
        $first = $this->getJson('/reimbursements/notices?unread=1')->assertOk()->assertJsonCount(50, 'notices.data')->assertJsonPath('notices.total', 51);
        $next = $first->json('notices.next_page_url');
        $this->assertStringContainsString('unread=1', $next);
        $this->getJson($next)->assertOk()->assertJsonCount(1, 'notices.data')->assertJsonPath('notices.data.0.read_at', null);
        $this->getJson('/reimbursements/notices')->assertJsonPath('notices.total', 52);
    }

    public function test_notice_retry_never_requeues_sent_denied_or_uncertain_deliveries(): void
    {
        $notice = ReimbursementNotice::factory()->create(['delivery_status' => 'failed', 'attempts' => 3]);
        $this->artisan('reimbursements:retry-notice', ['id' => $notice->id])->assertSuccessful();
        $this->assertSame('pending', $notice->fresh()->delivery_status);
        $this->assertSame(0, $notice->fresh()->attempts);
        foreach (['sent', 'unknown', 'denied'] as $state) {
            $notice->refresh()->update(['delivery_status' => $state]);
            $this->artisan('reimbursements:retry-notice', ['id' => $notice->id])->assertFailed();
            $this->assertSame($state, $notice->fresh()->delivery_status);
        }
    }
}
