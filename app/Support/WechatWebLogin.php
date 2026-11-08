<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class WechatWebLogin
{
    public function key(string $id): string
    {
        abort_unless(preg_match('/^[a-f0-9]{32}$/', $id), 404);

        return 'web-wechat-login:'.$id;
    }

    public function read(string $id): array
    {
        $state = Cache::get($this->key($id));
        abort_unless($state && $state['expires'] > now()->timestamp, 410, '二维码已过期，请在电脑端刷新。');

        return $state;
    }

    public function image(string $id): string
    {
        try {
            $token = Cache::remember('reimbursement-wechat-token:'.config('wechat.appid'), 6000, function (): string {
                $response = Http::connectTimeout(3)->timeout(10)->get('https://api.weixin.qq.com/cgi-bin/token', ['grant_type' => 'client_credential', 'appid' => config('wechat.appid'), 'secret' => config('wechat.secret')]);
                if (! $response->successful() || ! is_string($response->json('access_token'))) {
                    throw new \RuntimeException;
                }

                return $response->json('access_token');
            });
            $response = Http::connectTimeout(3)->timeout(15)->post('https://api.weixin.qq.com/wxa/getwxacodeunlimit?access_token='.urlencode($token), ['scene' => $id, 'page' => 'pages/web-login/web-login', 'check_path' => true, 'env_version' => config('wechat.web_login_version'), 'width' => 280]);
            if (! $response->successful() || ! str_starts_with($response->header('Content-Type') ?? '', 'image/')) {
                throw new \RuntimeException;
            }

            return 'data:image/png;base64,'.base64_encode($response->body());
        } catch (\Throwable) {
            throw ValidationException::withMessages(['wechat' => '暂时无法生成登录码，请确认小程序对应版本已发布，或使用密码登录。']);
        }
    }
}
