<?php

namespace App\Integrations\Feishu;

class FeishuCallbackVerifier
{
    /** @param array<string, mixed> $payload */
    public function verify(array $payload): bool
    {
        $expectedToken = (string) config('services.feishu.verification_token');
        $token = (string) data_get($payload, 'header.token', $payload['token'] ?? '');
        if ($expectedToken === '' || ! hash_equals($expectedToken, $token)) {
            return false;
        }

        $expectedAppId = (string) config('services.feishu.app_id');
        $appId = (string) data_get($payload, 'header.app_id', $expectedAppId);
        if ($expectedAppId !== '' && ! hash_equals($expectedAppId, $appId)) {
            return false;
        }

        $expectedTenant = (string) config('services.feishu.tenant_key');
        $tenant = (string) data_get($payload, 'header.tenant_key', '');

        return $expectedTenant === '' || $tenant === '' || hash_equals($expectedTenant, $tenant);
    }
}
