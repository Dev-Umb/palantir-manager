<?php

namespace Tests\Feature;

use App\Integrations\Wechat\WechatClient;
use App\Jobs\SendReimbursementWechatNotice;
use App\Models\Reimbursement;
use App\Models\ReimbursementNotice;
use App\Models\ReimbursementVersion;
use App\Models\User;
use App\Support\WechatSubscriptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WechatNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'wechat.appid' => 'wx-test',
            'wechat.secret' => 'secret-test',
            'wechat.notify.enabled' => true,
            'wechat.notify.state' => 'formal',
            'wechat.notify.templates.reimbursement_approval.id' => 'tmpl-approval',
            'wechat.notify.templates.reimbursement_result.id' => 'tmpl-result',
        ]);
        Cache::flush();
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    public function test_approver_reminder_uses_real_openid_and_approval_template(): void
    {
        $owner = User::factory()->create(['name' => '张三']);
        $approver = User::factory()->create(['name' => '李四']);
        $version = $this->version($owner, status: 'pending', approver: $approver);
        $this->bind($approver, 'approver-openid');
        WechatSubscriptions::grant($approver->id, 'reimbursement_approval');
        $notice = $this->notice($version, $approver, 'approval:0:'.now('Asia/Shanghai')->toDateString());

        $this->fakeSend(['errcode' => 0]);
        (new SendReimbursementWechatNotice($notice->id))->handle(app(WechatClient::class));

        $this->assertSame('sent', $notice->fresh()->delivery_status);
        $this->assertSame(0, $this->grantCount($approver->id, 'tmpl-approval'));
        $request = $this->sentRequest();
        $this->assertSame('approver-openid', $request['touser']);
        $this->assertNotSame(hash('sha256', 'approver-openid'), $request['touser']);
        $this->assertSame('tmpl-approval', $request['template_id']);
        $this->assertSame('pages/reimbursements/index?approval=1', $request['page']);
        $this->assertSame('差旅交通', $request['data']['thing16']['value']);
        $this->assertSame('张三', $request['data']['thing17']['value']);
        $this->assertSame('123.45', $request['data']['amount19']['value']);
        $this->assertMatchesRegularExpression('/^\d{4}年\d{1,2}月\d{1,2}日 \d{2}:\d{2}$/', $request['data']['time18']['value']);
    }

    public function test_initiator_result_uses_status_title_reason_and_original_binding(): void
    {
        $owner = User::factory()->create(['name' => '张三']);
        $approver = User::factory()->create();
        $bindingVersion = (string) Str::uuid();
        $version = $this->version($owner, status: 'rejected', approver: $approver, bindingVersion: $bindingVersion);
        $this->bind($owner, 'owner-openid', $bindingVersion);
        WechatSubscriptions::grant($owner->id, 'reimbursement_result');
        $notice = $this->notice($version, $owner, 'rejected');

        $this->fakeSend(['errcode' => 0]);
        (new SendReimbursementWechatNotice($notice->id))->handle(app(WechatClient::class));

        $this->assertSame('sent', $notice->fresh()->delivery_status);
        $request = $this->sentRequest();
        $this->assertSame('owner-openid', $request['touser']);
        $this->assertSame('tmpl-result', $request['template_id']);
        $this->assertSame('已驳回', $request['data']['phrase1']['value']);
        $this->assertSame('客户拜访', $request['data']['thing2']['value']);
        $this->assertSame('发票抬头不符', $request['data']['thing5']['value']);
        $this->assertMatchesRegularExpression('/^\d{4}年\d{1,2}月\d{1,2}日 \d{2}:\d{2}$/', $request['data']['date3']['value']);
    }

    public function test_missing_grant_denies_without_calling_wechat(): void
    {
        $owner = User::factory()->create();
        $approver = User::factory()->create();
        $version = $this->version($owner, status: 'pending', approver: $approver);
        $this->bind($approver, 'approver-openid');
        $notice = $this->notice($version, $approver, 'approval:0');

        (new SendReimbursementWechatNotice($notice->id))->handle(app(WechatClient::class));

        $this->assertSame('denied', $notice->fresh()->delivery_status);
        $this->assertSame('no_subscription', $notice->fresh()->delivery_error);
        Http::assertNothingSent();
    }

    public function test_wechat_43101_clears_grant_and_marks_denied(): void
    {
        [$notice, $user] = $this->pendingApproval();
        $this->fakeSend(['errcode' => 43101, 'errmsg' => 'user refuse']);

        (new SendReimbursementWechatNotice($notice->id))->handle(app(WechatClient::class));

        $this->assertSame('denied', $notice->fresh()->delivery_status);
        $this->assertSame('43101', $notice->fresh()->delivery_error);
        $this->assertSame(0, $this->grantCount($user->id, 'tmpl-approval'));
    }

    public function test_uncertain_transport_never_resends_or_restores_grant(): void
    {
        [$notice, $user] = $this->pendingApproval();
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'fake-token']),
            'api.weixin.qq.com/cgi-bin/message/subscribe/send*' => Http::failedConnection(),
        ]);

        (new SendReimbursementWechatNotice($notice->id))->handle(app(WechatClient::class));
        (new SendReimbursementWechatNotice($notice->id))->handle(app(WechatClient::class));

        $this->assertSame('unknown', $notice->fresh()->delivery_status);
        $this->assertSame('transport_uncertain', $notice->fresh()->delivery_error);
        $this->assertSame(0, $this->grantCount($user->id, 'tmpl-approval'));
        $this->assertSame(2, collect(Http::recorded())->count());
    }

    public function test_definitive_error_restores_grant_without_retry(): void
    {
        [$notice, $user] = $this->pendingApproval();
        $this->fakeSend(['errcode' => 47003, 'errmsg' => 'invalid data']);

        (new SendReimbursementWechatNotice($notice->id))->handle(app(WechatClient::class));

        $this->assertSame('failed', $notice->fresh()->delivery_status);
        $this->assertSame('47003', $notice->fresh()->delivery_error);
        $this->assertSame(1, $this->grantCount($user->id, 'tmpl-approval'));
        $this->assertSame(1, $notice->fresh()->attempts);
    }

    public function test_result_permission_is_bound_to_the_submission_wechat(): void
    {
        $owner = User::factory()->create();
        $approver = User::factory()->create();
        $version = $this->version($owner, status: 'approved', approver: $approver, bindingVersion: (string) Str::uuid());
        $this->bind($owner, 'other-openid', (string) Str::uuid());
        WechatSubscriptions::grant($owner->id, 'reimbursement_result');
        $notice = $this->notice($version, $owner, 'approved');

        (new SendReimbursementWechatNotice($notice->id))->handle(app(WechatClient::class));

        $this->assertSame('unavailable', $notice->fresh()->delivery_status);
        Http::assertNothingSent();
    }

    public function test_cc_notice_is_never_pushed(): void
    {
        $owner = User::factory()->create();
        $approver = User::factory()->create();
        $version = $this->version($owner, status: 'pending', approver: $approver);
        $this->bind($owner, 'owner-openid');
        WechatSubscriptions::grant($owner->id, 'reimbursement_result');
        $notice = $this->notice($version, $owner, 'cc:submitted');

        (new SendReimbursementWechatNotice($notice->id))->handle(app(WechatClient::class));

        $this->assertSame('not_required', $notice->fresh()->delivery_status);
        Http::assertNothingSent();
    }

    public function test_subscription_status_and_accept_report_only_configured_templates(): void
    {
        $user = User::factory()->create();
        $this->bind($user);

        $this->getJson('/miniapp/wechat/subscriptions')->assertUnauthorized();
        $this->actingAs($user)->getJson('/miniapp/wechat/subscriptions')
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('bound', true)
            ->assertJsonPath('addressable', true)
            ->assertJsonPath('templates.0.key', 'reimbursement_approval')
            ->assertJsonPath('grants.reimbursement_approval', 0)
            ->assertJsonPath('grants.reimbursement_result', 0);

        $this->postJson('/miniapp/wechat/subscriptions', ['accepted' => ['reimbursement_approval', 'unknown_key']])
            ->assertOk()
            ->assertJsonPath('accepted', ['reimbursement_approval'])
            ->assertJsonPath('grants.reimbursement_approval', 1);
        $this->postJson('/miniapp/wechat/subscriptions', ['accepted' => ['reimbursement_approval', 'reimbursement_result']])
            ->assertOk()
            ->assertJsonPath('grants.reimbursement_approval', 2)
            ->assertJsonPath('grants.reimbursement_result', 1);

        config(['wechat.notify.enabled' => false]);
        $this->postJson('/miniapp/wechat/subscriptions', ['accepted' => ['reimbursement_approval']])->assertUnprocessable();
        $this->getJson('/miniapp/wechat/subscriptions')->assertJsonPath('configured', false);
    }

    public function test_refresh_persists_addressable_openid_only_for_matching_identity(): void
    {
        $user = User::factory()->create();
        $this->bind($user, 'real-openid-a', addressable: false);

        Http::fake(['api.weixin.qq.com/sns/jscode2session*' => Http::response(['openid' => 'real-openid-a'])]);
        $this->actingAs($user)->postJson('/miniapp/wechat/refresh', ['code' => 'refresh-one'])->assertOk()->assertJsonPath('ok', true);
        $row = DB::table('wechat_account_bindings')->where('user_id', $user->id)->first();
        $this->assertSame('real-openid-a', Crypt::decryptString($row->openid_encrypted));
        $this->assertSame(hash('sha256', 'real-openid-a'), $row->openid);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.weixin.qq.com/sns/jscode2session*' => Http::response(['openid' => 'real-openid-b'])]);
        $this->postJson('/miniapp/wechat/refresh', ['code' => 'refresh-two'])->assertUnprocessable()->assertJsonValidationErrors('wechat');
        $this->assertSame('real-openid-a', Crypt::decryptString(DB::table('wechat_account_bindings')->where('user_id', $user->id)->value('openid_encrypted')));

        $unbound = User::factory()->create();
        $this->actingAs($unbound)->postJson('/miniapp/wechat/refresh', ['code' => 'refresh-three'])->assertUnprocessable();
    }

    /** @return array{0: ReimbursementNotice, 1: User} */
    private function pendingApproval(): array
    {
        $owner = User::factory()->create();
        $approver = User::factory()->create();
        $version = $this->version($owner, status: 'pending', approver: $approver);
        $this->bind($approver, 'approver-openid');
        WechatSubscriptions::grant($approver->id, 'reimbursement_approval');

        return [$this->notice($version, $approver, 'approval:0'), $approver];
    }

    private function version(User $owner, string $status, User $approver, ?string $bindingVersion = null): ReimbursementVersion
    {
        $reimbursement = Reimbursement::factory()->create(['user_id' => $owner->id]);

        $version = ReimbursementVersion::factory()->create([
            'reimbursement_id' => $reimbursement->id,
            'user_id' => $owner->id,
            'title' => '客户拜访',
            'type' => '差旅交通',
            'total_cents' => 12345,
            'status' => $status,
            'node_index' => 0,
            'flow' => [
                'nodes' => [['name' => '审批', 'mode' => 'any', 'people' => [['id' => $approver->id, 'name' => $approver->name]]]],
                'cc' => [],
                'triggers' => [],
            ],
            'decisions' => $status === 'rejected'
                ? [['node' => 0, 'user_id' => $approver->id, 'name' => $approver->name, 'decision' => 'reject', 'note' => '发票抬头不符', 'at' => now()->toISOString()]]
                : [],
            'wechat_binding_version' => $bindingVersion,
        ]);
        foreach (['approver' => $approver, 'owner' => $owner] as $kind => $user) {
            DB::table('reimbursement_access')->insert(['version_id' => $version->id, 'user_id' => $user->id, 'kind' => $kind]);
        }

        return $version;
    }

    private function notice(ReimbursementVersion $version, User $user, string $event): ReimbursementNotice
    {
        return ReimbursementNotice::forceCreate([
            'delivery_day' => now('Asia/Shanghai')->toDateString(),
            'version_id' => $version->id,
            'user_id' => $user->id,
            'event' => $event,
            'message' => '测试通知',
            'delivery_status' => 'pending',
        ]);
    }

    private function bind(User $user, string $openid = 'openid-a', ?string $version = null, bool $addressable = true): void
    {
        DB::table('wechat_account_bindings')->insert([
            'user_id' => $user->id,
            'appid' => 'wx-test',
            'openid' => hash('sha256', $openid),
            'openid_encrypted' => $addressable ? Crypt::encryptString($openid) : null,
            'version' => $version ?? (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fakeSend(array $response): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'fake-token']),
            'api.weixin.qq.com/cgi-bin/message/subscribe/send*' => Http::response($response),
        ]);
    }

    /** @return array<string, mixed> */
    private function sentRequest(): array
    {
        return collect(Http::recorded())
            ->first(fn (array $exchange): bool => str_contains($exchange[0]->url(), '/subscribe/send'))[0]
            ->data();
    }

    private function grantCount(int $userId, string $templateId): int
    {
        return (int) DB::table('wechat_subscription_grants')->where('user_id', $userId)->where('template_id', $templateId)->value('available_count');
    }
}
