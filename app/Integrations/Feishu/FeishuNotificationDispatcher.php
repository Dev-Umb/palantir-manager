<?php

namespace App\Integrations\Feishu;

use App\Jobs\DeliverFeishuNotification;
use App\Models\NotificationDelivery;
use App\Models\ProjectNotification;
use App\Models\TenderNotification;

class FeishuNotificationDispatcher
{
    public function dispatch(ProjectNotification|TenderNotification $notification): NotificationDelivery
    {
        $sourceType = $notification instanceof ProjectNotification ? 'project_notification' : 'tender_notification';
        $occurrence = (int) $notification->occurrences;
        $key = "{$sourceType}:{$notification->id}:{$notification->user_id}:{$occurrence}:feishu";
        $delivery = NotificationDelivery::firstOrCreate(
            ['idempotency_key' => $key],
            [
                'source_type' => $sourceType,
                'source_id' => (string) $notification->id,
                'user_id' => $notification->user_id,
                'channel' => 'feishu',
                'occurrence' => $occurrence,
                'status' => config('services.feishu.enabled')
                    ? NotificationDelivery::STATUS_PENDING
                    : NotificationDelivery::STATUS_SKIPPED,
                'last_error' => config('services.feishu.enabled') ? null : 'integration_disabled',
            ],
        );

        if ($delivery->wasRecentlyCreated && $delivery->status === NotificationDelivery::STATUS_PENDING) {
            DeliverFeishuNotification::dispatch($delivery->id)->afterCommit();
        }

        return $delivery;
    }
}
