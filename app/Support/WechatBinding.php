<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Reads the addressable WeChat identity for a bound account.
 *
 * The `openid` column keeps only a digest for case-safe identity matching; the
 * decryptable copy used to address subscribe messages lives in
 * `openid_encrypted` and is never exposed outside the server.
 */
class WechatBinding
{
    public static function openid(int $userId, ?string $version = null): ?string
    {
        $encrypted = DB::table('wechat_account_bindings')
            ->where('user_id', $userId)
            ->where('appid', (string) config('wechat.appid'))
            ->when($version !== null, fn ($query) => $query->where('version', $version))
            ->value('openid_encrypted');
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function addressable(int $userId): bool
    {
        return self::openid($userId) !== null;
    }
}
