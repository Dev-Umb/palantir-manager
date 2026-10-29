<?php

namespace Tests\Feature;

use App\Jobs\SendReimbursementWechatNotice;
use App\Models\Permission;
use App\Models\ReimbursementNotice;
use App\Models\ReimbursementUpload;
use App\Models\ReimbursementVersion;
use App\Models\ReimbursementWorkflow;
use App\Models\Role;
use App\Models\StoredAttachment;
use App\Models\User;
use App\Support\BusinessFileStorage;
use App\Support\InvoicePdfRecognition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
