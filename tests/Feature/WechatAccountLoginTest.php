<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\WechatLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WechatAccountLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wechat.appid' => 'wx-test', 'wechat.secret' => 'fake-only']);
        Cache::flush();
        Http::preventStrayRequests();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.weixin.qq.com/*' => Http::response(['openid' => 'identity-a', 'session_key' => 'must-not-leak'])]);
    }

    private function issue(User $user): string
    {
        return app(WechatLogin::class)->issue($user)['code'];
    }

    public function test_bind_login_and_unbind_revokes_miniapp_session_but_preserves_password_login(): void
    {
        $user = User::factory()->create();
        $code = $this->issue($user);
        $this->postJson('/miniapp/wechat/login', ['code' => 'wx-one', 'binding_code' => $code])->assertOk()->assertJsonPath('user.id', $user->id)->assertDontSee('must-not-leak')->assertDontSee('identity-a');
        $this->assertAuthenticatedAs($user);
        $binding = session('wechat_binding');
        $this->deleteJson('/miniapp/session')->assertOk();
        $this->postJson('/miniapp/wechat/login', ['code' => 'wx-two'])->assertOk()->assertJsonPath('user.id', $user->id);
        app(WechatLogin::class)->unbind($user, $user);
        $this->withSession(['wechat_binding' => $binding])->getJson('/miniapp/session')->assertUnauthorized();
        $this->postJson('/miniapp/session', ['email' => $user->email, 'password' => 'password'])->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'wechat.unbind', 'subject_id' => (string) $user->id]);
    }

    public function test_unbound_and_replayed_wechat_codes_cannot_create_accounts(): void
    {
        $this->postJson('/miniapp/wechat/login', ['code' => 'wx-one'])->assertUnprocessable()->assertJsonValidationErrors('binding_code');
        $this->postJson('/miniapp/wechat/login', ['code' => 'wx-one'])->assertUnprocessable()->assertJsonValidationErrors('wechat');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        Http::assertSentCount(1);
    }

    public function test_expired_superseded_and_consumed_binding_codes_fail(): void
    {
        $user = User::factory()->create();
        $old = $this->issue($user);
        $fresh = $this->issue($user);
        $this->postJson('/miniapp/wechat/login', ['code' => 'wx-1', 'binding_code' => $old])->assertUnprocessable();
        $this->travel(11)->minutes();
        $this->postJson('/miniapp/wechat/login', ['code' => 'wx-2', 'binding_code' => $fresh])->assertUnprocessable();
        $fresh = $this->issue($user);
        $this->postJson('/miniapp/wechat/login', ['code' => 'wx-3', 'binding_code' => $fresh])->assertOk();
        $this->deleteJson('/miniapp/session');
        $this->postJson('/miniapp/wechat/login', ['code' => 'wx-4', 'binding_code' => $fresh])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_identity_conflict_does_not_transfer_binding_or_consume_other_account_code(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->postJson('/miniapp/wechat/login', ['code' => 'wx-1', 'binding_code' => $this->issue($a)])->assertOk();
        $this->deleteJson('/miniapp/session');
        $code = $this->issue($b);
        $this->postJson('/miniapp/wechat/login', ['code' => 'wx-2', 'binding_code' => $code])->assertUnprocessable();
        $this->assertDatabaseHas('wechat_account_bindings', ['user_id' => $a->id, 'openid' => hash('sha256', 'identity-a')]);
        $this->assertDatabaseHas('wechat_account_bindings', ['user_id' => $b->id, 'openid' => null, 'code_hash' => hash('sha256', $code)]);
    }

    public function test_issue_and_unbind_require_password_and_admin_endpoint_requires_permission(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->postJson('/settings/wechat/code', ['current_password' => 'password'])->assertUnauthorized();
        $this->actingAs($user)->postJson('/settings/wechat/code', ['current_password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/settings/wechat/code', ['current_password' => 'password'])->assertOk()->assertJsonStructure(['code', 'expires_at']);
        $this->deleteJson('/settings/wechat', ['current_password' => 'wrong'])->assertUnprocessable();
        $this->deleteJson('/admin/users/'.$other->id.'/wechat')->assertForbidden();
        $this->delete('/settings/wechat', ['current_password' => 'password'])->assertRedirect();
    }

    public function test_upstream_failure_and_missing_config_are_sanitized(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.weixin.qq.com/*' => Http::response(['errcode' => 40013, 'errmsg' => 'fake-only'])]);
        $this->postJson('/miniapp/wechat/login', ['code' => 'wx-1'])->assertUnprocessable()->assertDontSee('fake-only');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.weixin.qq.com/*' => Http::failedConnection()]);
        $this->postJson('/miniapp/wechat/login', ['code' => 'wx-2'])->assertUnprocessable()->assertDontSee('fake-only');
        config(['wechat.secret' => '']);
        $this->getJson('/miniapp/session')->assertJsonPath('wechatConfigured', false);
        $this->postJson('/miniapp/wechat/login', ['code' => 'wx-3'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_deleted_account_and_tampered_target_cannot_authenticate(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $code = $this->issue($user);
        $this->postJson('/miniapp/wechat/login', ['code' => 'wx-1', 'binding_code' => $code, 'user_id' => $other->id, 'openid' => 'attacker'])->assertOk()->assertJsonPath('user.id', $user->id);
        $this->deleteJson('/miniapp/session');
        $user->delete();
        $this->postJson('/miniapp/wechat/login', ['code' => 'wx-2'])->assertNotFound();
        $this->assertGuest();
    }

    public function test_case_different_wechat_identities_do_not_share_accounts(): void
    {
        $user = User::factory()->create();
        $this->postJson('/miniapp/wechat/login', ['code' => 'case-one', 'binding_code' => $this->issue($user)])->assertOk();
        $this->deleteJson('/miniapp/session');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.weixin.qq.com/*' => Http::response(['openid' => 'IDENTITY-A'])]);
        $this->postJson('/miniapp/wechat/login', ['code' => 'case-two'])->assertUnprocessable()->assertJsonValidationErrors('binding_code');
        $this->assertGuest();
    }
}
