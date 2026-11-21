<?php

namespace App\Integrations\Wechat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WechatClient
{
    private const TOKEN_CACHE_SECONDS = 6000;

    private const TOKEN_ERRORS = [40001, 40014, 42001];

    public function configured(): bool
    {
        return filled(config('wechat.appid')) && filled(config('wechat.secret'));
    }

    /**
     * @param  array<string, array{value: string}>  $data
     * @return array{errcode: int, errmsg: string}
     */
    public function sendSubscribeMessage(string $openid, string $templateId, array $data, ?string $page = null): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('wechat_not_configured');
        }

        $payload = [
            'touser' => $openid,
            'template_id' => $templateId,
            'miniprogram_state' => (string) config('wechat.notify.state', 'formal'),
            'lang' => 'zh_CN',
            'data' => $data,
        ];
        if (is_string($page) && $page !== '') {
            $payload['page'] = $page;
        }

        $result = $this->post($payload, $this->token());
        if (in_array($result['errcode'], self::TOKEN_ERRORS, true)) {
            $result = $this->post($payload, $this->token(forceRefresh: true));
        }

        return $result;
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload, string $token): array
    {
        $response = Http::connectTimeout(5)
            ->timeout(15)
            ->post('https://api.weixin.qq.com/cgi-bin/message/subscribe/send?access_token='.urlencode($token), $payload);
        $result = $response->json();
        if (! is_array($result) || ! array_key_exists('errcode', $result)) {
            throw new RuntimeException('wechat_invalid_response');
        }

        return ['errcode' => (int) $result['errcode'], 'errmsg' => (string) ($result['errmsg'] ?? '')];
    }

    private function token(bool $forceRefresh = false): string
    {
        $key = 'wechat-access-token:'.config('wechat.appid');
        if ($forceRefresh) {
            Cache::forget($key);
        }

        return (string) Cache::remember($key, self::TOKEN_CACHE_SECONDS, function (): string {
            try {
                $response = Http::connectTimeout(5)
                    ->timeout(10)
                    ->get('https://api.weixin.qq.com/cgi-bin/token', [
                        'grant_type' => 'client_credential',
                        'appid' => config('wechat.appid'),
                        'secret' => config('wechat.secret'),
                    ]);
            } catch (ConnectionException) {
                throw new RuntimeException('wechat_token_unavailable');
            }

            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token) || $token === '') {
                throw new RuntimeException('wechat_token_unavailable');
            }

            return $token;
        });
    }
}
