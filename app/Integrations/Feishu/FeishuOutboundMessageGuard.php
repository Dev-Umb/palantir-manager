<?php

namespace App\Integrations\Feishu;

use App\Exceptions\FeishuOutboundCooldownException;
use Closure;
use Illuminate\Support\Facades\Cache;

class FeishuOutboundMessageGuard
{
    private const COOLDOWN_SECONDS = 10;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $send
     * @return TResult
     */
    public function send(string $destinationType, string $destinationId, Closure $send): mixed
    {
        $fingerprint = hash('sha256', "{$destinationType}:{$destinationId}");
        $lockKey = "feishu:outbound-message:lock:{$fingerprint}";
        $lastSentKey = "feishu:outbound-message:last-sent:{$fingerprint}";

        return Cache::lock($lockKey, 60)->block(5, function () use ($lastSentKey, $send): mixed {
            $lastSentAt = Cache::get($lastSentKey);
            if (is_numeric($lastSentAt)) {
                $elapsedSeconds = max(0, now()->getTimestamp() - (int) $lastSentAt);
                if ($elapsedSeconds < self::COOLDOWN_SECONDS) {
                    throw new FeishuOutboundCooldownException(self::COOLDOWN_SECONDS - $elapsedSeconds);
                }
            }

            $result = $send();
            Cache::put($lastSentKey, now()->getTimestamp(), now()->addMinute());

            return $result;
        });
    }
}
