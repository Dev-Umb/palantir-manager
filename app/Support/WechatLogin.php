<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WechatLogin
{
    public function configured(): bool
    {
        return filled(config('wechat.appid')) && filled(config('wechat.secret'));
    }

    public function identity(string $code): string
    {
        if (! $this->configured()) {
            throw ValidationException::withMessages(['wechat' => '微信登录尚未配置，请联系管理员。']);
        }
        if (! Cache::add('wechat-login:'.hash('sha256', config('wechat.appid').$code), true, 600)) {
            throw ValidationException::withMessages(['wechat' => '微信凭证已使用，请重新点击微信登录。']);
        }
        try {
            $response = Http::connectTimeout(3)->timeout(10)->get('https://api.weixin.qq.com/sns/jscode2session', [
                'appid' => config('wechat.appid'), 'secret' => config('wechat.secret'), 'js_code' => $code, 'grant_type' => 'authorization_code',
            ]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['wechat' => '微信服务连接失败，请重新点击微信登录。']);
        }
        $openid = $response->json('openid');
        if (! $response->successful() || $response->json('errcode', 0) != 0 || ! is_string($openid) || $openid === '' || strlen($openid) > 128) {
            throw ValidationException::withMessages(['wechat' => '微信身份验证失败，请重试或联系管理员检查小程序配置。']);
        }

        // Store a canonical digest so case-insensitive database collations cannot merge identities.
        return hash('sha256', $openid);
    }

    /** @return array{bound: bool, configured: bool} */
    public function state(User $user): array
    {
        return ['bound' => DB::table('wechat_account_bindings')->where('user_id', $user->id)->where('appid', config('wechat.appid'))->whereNotNull('openid')->exists(), 'configured' => $this->configured()];
    }

    /** @return array{code: string, expires_at: string} */
    public function issue(User $user): array
    {
        abort_unless($this->configured(), 422, '微信登录尚未配置。');

        return DB::transaction(function () use ($user): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('wechat_account_bindings')->where('user_id', $user->id)->where('appid', config('wechat.appid'))->lockForUpdate()->first();
            if ($row && $row->openid) {
                throw ValidationException::withMessages(['wechat' => '账号已绑定微信，请先解绑。']);
            }
            $code = strtoupper(bin2hex(random_bytes(8)));
            $expires = now()->addMinutes(10);
            DB::table('wechat_account_bindings')->updateOrInsert(['user_id' => $user->id, 'appid' => config('wechat.appid')], [
                'code_hash' => hash('sha256', $code), 'code_expires_at' => $expires, 'version' => (string) Str::uuid(), 'created_at' => $row?->created_at ?? now(), 'updated_at' => now(),
            ]);

            return ['code' => $code, 'expires_at' => $expires->toIso8601String()];
        });
    }

    public function authenticate(string $openid, ?string $code): object
    {
        try {
            return DB::transaction(function () use ($openid, $code): object {
                $query = DB::table('wechat_account_bindings')->where('appid', config('wechat.appid'));
                $row = ($code ? $query->where('code_hash', hash('sha256', strtoupper($code))) : $query->where('openid', $openid))->lockForUpdate()->first();
                if (! $row || ($code && (! $row->code_expires_at || now()->gte($row->code_expires_at)))) {
                    throw ValidationException::withMessages(['binding_code' => $code ? '绑定码无效或已过期。若刚刚已完成绑定，请返回微信登录；否则在后台重新生成。' : '当前微信尚未绑定，请先在平台后台生成绑定码。']);
                }
                User::query()->whereKey($row->user_id)->firstOrFail();
                if ($code) {
                    if ($row->openid) {
                        throw ValidationException::withMessages(['binding_code' => '账号已绑定，请先解绑后重试。']);
                    }
                    DB::table('wechat_account_bindings')->where('id', $row->id)->update(['openid' => $openid, 'bound_at' => now(), 'code_hash' => null, 'code_expires_at' => null, 'updated_at' => now()]);
                    $this->audit($row->user_id, $row->user_id, 'wechat.bind');
                }

                return $row;
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['binding_code' => '该微信已绑定其他账号，请先解绑。']);
            }
            throw $exception;
        }
    }

    public function unbind(User $target, User $actor): void
    {
        DB::transaction(function () use ($target, $actor): void {
            $row = DB::table('wechat_account_bindings')->where('user_id', $target->id)->where('appid', config('wechat.appid'))->lockForUpdate()->first();
            if ($row) {
                DB::table('wechat_account_bindings')->where('id', $row->id)->update(['openid' => null, 'bound_at' => null, 'code_hash' => null, 'code_expires_at' => null, 'version' => (string) Str::uuid(), 'updated_at' => now()]);
                $this->audit($target->id, $actor->id, 'wechat.unbind');
            }
        });
    }

    private function audit(int $target, int $actor, string $action): void
    {
        AuditLog::query()->create(['user_id' => $actor, 'action' => $action, 'subject_type' => User::class, 'subject_id' => (string) $target, 'payload' => []]);
    }
}
