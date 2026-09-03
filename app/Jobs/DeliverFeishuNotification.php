<?php

namespace App\Jobs;

use App\Integrations\Feishu\FeishuClient;
use App\Integrations\Feishu\FeishuMessageRenderer;
use App\Models\FeishuUserBinding;
use App\Models\NotificationDelivery;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DeliverFeishuNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(FeishuClient $client, FeishuMessageRenderer $renderer): void
    {
        $delivery = NotificationDelivery::findOrFail($this->deliveryId);
        if ($delivery->status !== NotificationDelivery::STATUS_PENDING) {
            return;
        }

        Cache::lock("feishu:notification-delivery:user:{$delivery->user_id}", 30)
            ->block(10, function () use ($client, $delivery, $renderer): void {
                $deliveries = NotificationDelivery::query()
                    ->where('user_id', $delivery->user_id)
                    ->where('channel', 'feishu')
                    ->where('status', NotificationDelivery::STATUS_PENDING)
                    ->orderBy('id')
                    ->get();
                if ($deliveries->isEmpty()) {
                    return;
                }

                $binding = FeishuUserBinding::active()->where('user_id', $delivery->user_id)->first();
                if (! $binding) {
                    NotificationDelivery::query()->whereKey($deliveries->modelKeys())->update([
                        'status' => NotificationDelivery::STATUS_SKIPPED,
                        'last_error' => 'recipient_not_bound',
                    ]);

                    return;
                }

                NotificationDelivery::query()->whereKey($deliveries->modelKeys())->increment('attempts');
                if ($deliveries->count() > 1) {
                    $result = $client->sendCard($binding->open_id, $renderer->renderBatchCard($deliveries));
                } else {
                    $single = $deliveries->sole();
                    $card = $renderer->renderCard($single);
                    $result = $card
                        ? $client->sendCard($binding->open_id, $card)
                        : $client->sendText($binding->open_id, $renderer->render($single));
                }
                NotificationDelivery::query()->whereKey($deliveries->modelKeys())->update([
                    'status' => NotificationDelivery::STATUS_SENT,
                    'external_message_id' => $result['message_id'],
                    'last_error' => null,
                    'sent_at' => now(),
                ]);
            });
    }

    public function failed(?Throwable $exception): void
    {
        NotificationDelivery::whereKey($this->deliveryId)->update([
            'status' => NotificationDelivery::STATUS_FAILED,
            'last_error' => mb_substr($exception?->getMessage() ?: 'delivery_failed', 0, 500),
        ]);
    }
}
