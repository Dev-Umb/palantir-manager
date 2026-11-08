<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\WechatLogin;
use App\Support\WechatWebLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebWechatLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wechat.appid' => 'wx-test', 'wechat.secret' => 'test-secret']);
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(['*/cgi-bin/token*' => Http::response(['access_token' => 'private-token']), '*/wxa/getwxacodeunlimit*' => Http::response('png', 200, ['Content-Type' => 'image/png'])]);
    }

    private function createChallenge(): string
    {
        return $this->postJson('/login/wechat')->assertOk()->assertJsonPath('expires_in', 180)->assertDontSee('private-token')->json('id');
    }

    private function binding(User $user): array
    {
        $wechat = app(WechatLogin::class);
        $code = $wechat->issue($user)['code'];
        $row = $wechat->authenticate(hash('sha256', 'identity-'.$user->id), $code);

        return ['id' => $row->id, 'version' => $row->version];
    }

    private function confirm(string $id, User $user): array
    {
        $binding = $this->binding($user);
        $this->actingAs($user)->withSession(['wechat_binding' => $binding])->postJson('/miniapp/web-login/'.$id, ['confirm' => true])->assertOk()->assertJsonPath('status', 'confirmed');
        Auth::logout();
        $this->withSession(['wechat_binding' => null]);

        return $binding;
    }

    public function test_confirm_consume_once_and_revoke_without_changing_permissions(): void
    {
        $id = $this->createChallenge();
        $user = User::factory()->create();
        $this->getJson('/login/wechat/'.$id)->assertJsonPath('status', 'pending');
        $this->postJson('/login/wechat/'.$id.'/consume')->assertConflict();
        $binding = $this->confirm($id, $user);
        $this->getJson('/login/wechat/'.$id)->assertJsonPath('status', 'confirmed');
        $this->postJson('/login/wechat/'.$id.'/consume')->assertOk()->assertJsonPath('redirect', route('home'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame($binding, session('wechat_binding'));
        $this->assertDatabaseCount('role_user', 0);
        Auth::logout();
        $this->withSession(['wechat_binding' => null]);
        $this->withSession(['web_wechat_challenge' => $id])->postJson('/login/wechat/'.$id.'/consume')->assertGone();
        app(WechatLogin::class)->unbind($user, $user);
        $this->actingAs($user)->withSession(['wechat_binding' => $binding])->getJson('/miniapp/session')->assertUnauthorized();
    }

    public function test_foreign_browser_cannot_poll_or_consume_and_refresh_expires_old_code(): void
    {
        $id = $this->createChallenge();
        $this->withSession(['web_wechat_challenge' => 'foreign'])->getJson('/login/wechat/'.$id)->assertNotFound();
        $this->postJson('/login/wechat/'.$id.'/consume')->assertNotFound();
        $this->withSession(['web_wechat_challenge' => $id]);
        $next = $this->createChallenge();
        $this->assertNotSame($id, $next);
        $this->assertNull(Cache::get(app(WechatWebLogin::class)->key($id)));
    }

    public function test_expiry_cancel_unbound_and_duplicate_confirmation(): void
    {
        $id = $this->createChallenge();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/miniapp/web-login/'.$id, ['confirm' => true])->assertForbidden();
        $binding = $this->binding($user);
        $this->withSession(['wechat_binding' => $binding])->postJson('/miniapp/web-login/'.$id, ['confirm' => false])->assertJsonPath('status', 'cancelled');
        $this->postJson('/miniapp/web-login/'.$id, ['confirm' => true])->assertConflict();
        Auth::logout();
        $this->withSession(['wechat_binding' => null]);
        $this->postJson('/login/wechat/'.$id.'/consume')->assertConflict();
        $this->travel(181)->seconds();
        $this->getJson('/login/wechat/'.$id)->assertGone();
        $this->assertGuest();
    }

    public function test_revocation_after_confirmation_blocks_consumption(): void
    {
        $id = $this->createChallenge();
        $user = User::factory()->create();
        $this->confirm($id, $user);
        app(WechatLogin::class)->unbind($user, $user);
        $this->postJson('/login/wechat/'.$id.'/consume')->assertForbidden();
        $this->assertGuest();
    }

    public function test_deleted_user_cannot_consume(): void
    {
        $id = $this->createChallenge();
        $user = User::factory()->create();
        $this->confirm($id, $user);
        $user->delete();
        $this->postJson('/login/wechat/'.$id.'/consume')->assertNotFound();
        $this->assertGuest();
    }

    public function test_code_generation_failure_and_existing_password_login(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/cgi-bin/token*' => Http::response(['access_token' => 'token']), '*/wxa/getwxacodeunlimit*' => Http::response(['errcode' => 41030])]);
        $this->postJson('/login/wechat')->assertUnprocessable()->assertJsonValidationErrors('wechat');
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
    }
}
