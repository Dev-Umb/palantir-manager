<?php

namespace App\Support;

use App\Models\WechatSubscriptionGrant;
use Illuminate\Support\Facades\DB;

/**
 * One-time subscribe quota ledger.
 *
 * WeChat grants exactly one send per acceptance and exposes no remaining
 * quota, so the server keeps a conservative lower bound per user/template.
 */
class WechatSubscriptions
{
    /** @return array<string, array{id: string, label: string}> */
    public static function templates(): array
    {
        $configured = config('wechat.notify.templates', []);
        $templates = [];
        foreach (is_array($configured) ? $configured : [] as $key => $template) {
            if (is_array($template) && filled($template['id'] ?? null)) {
                $templates[(string) $key] = [
                    'id' => (string) $template['id'],
                    'label' => (string) ($template['label'] ?? $key),
                ];
            }
        }

        return $templates;
    }

    public static function grant(int $userId, string $templateKey): void
    {
        $template = self::templates()[$templateKey] ?? null;
        if ($template === null) {
            return;
        }

        DB::transaction(function () use ($userId, $templateKey, $template): void {
            DB::table('wechat_subscription_grants')->insertOrIgnore([
                'user_id' => $userId,
                'template_key' => $templateKey,
                'template_id' => $template['id'],
                'available_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('wechat_subscription_grants')
                ->where('user_id', $userId)
                ->where('template_id', $template['id'])
                ->update([
                    'template_key' => $templateKey,
                    'available_count' => DB::raw('available_count + 1'),
                    'last_accepted_at' => now(),
                    'updated_at' => now(),
                ]);
        });
    }

    public static function available(int $userId, string $templateId): bool
    {
        return WechatSubscriptionGrant::query()
            ->where('user_id', $userId)
            ->where('template_id', $templateId)
            ->where('available_count', '>', 0)
            ->exists();
    }

    public static function consume(int $userId, string $templateId): bool
    {
        return WechatSubscriptionGrant::query()
            ->where('user_id', $userId)
            ->where('template_id', $templateId)
            ->where('available_count', '>', 0)
            ->decrement('available_count') > 0;
    }

    public static function restore(int $userId, string $templateId): void
    {
        WechatSubscriptionGrant::query()
            ->where('user_id', $userId)
            ->where('template_id', $templateId)
            ->increment('available_count');
    }

    public static function clear(int $userId, string $templateId): void
    {
        WechatSubscriptionGrant::query()
            ->where('user_id', $userId)
            ->where('template_id', $templateId)
            ->update(['available_count' => 0]);
    }

    /** @return array<string, int> */
    public static function counts(int $userId): array
    {
        $templates = self::templates();
        $grants = WechatSubscriptionGrant::query()
            ->where('user_id', $userId)
            ->whereIn('template_id', array_column($templates, 'id'))
            ->pluck('available_count', 'template_key')
            ->all();

        return collect($templates)
            ->mapWithKeys(fn (array $template, string $key): array => [$key => (int) ($grants[$key] ?? 0)])
            ->all();
    }
}
