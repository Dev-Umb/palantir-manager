<?php

namespace App\Jobs;

use App\Integrations\Feishu\FeishuClient;
use App\Integrations\Feishu\FeishuProcessingReaction;
use App\Integrations\Feishu\FeishuRollout;
use App\Models\FeishuInboundEvent;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendFeishuWorkflowReply implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public int $eventId)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return (string) $this->eventId;
    }

    public function backoff(): array
    {
        return [10, 20, 40, 80];
    }

    public function handle(FeishuClient $client, FeishuProcessingReaction $reaction): void
    {
        $event = FeishuInboundEvent::findOrFail($this->eventId);
        if ($event->status !== 'reply_pending' || $event->reply_message_id) {
            return;
        }

        $card = data_get($event->payload, '_palantir.pending_reply_card');
        if (! is_array($card)) {
            throw new \RuntimeException('feishu_pending_reply_card_missing');
        }

        $isGroup = data_get($event->payload, 'event.message.chat_type') === 'group';
        $reply = $isGroup && ! app(FeishuRollout::class)->isRestricted()
            ? $client->sendCardToChat((string) data_get($event->payload, 'event.message.chat_id'), $card)
            : $client->sendCard((string) $event->sender_open_id, $card);
        $payload = $event->payload;
        data_forget($payload, '_palantir.pending_reply_card');
        if (data_get($payload, '_palantir') === []) {
            data_forget($payload, '_palantir');
        }
        $event->update([
            'status' => 'completed',
            'payload' => $payload,
            'reply_message_id' => $reply['message_id'],
            'error' => null,
            'processed_at' => now(),
        ]);
        $reaction->remove($event->fresh());
    }

    public function failed(?Throwable $exception): void
    {
        $event = FeishuInboundEvent::find($this->eventId);
        $event?->update([
            'status' => 'failed',
            'error' => mb_substr($exception?->getMessage() ?: 'workflow_reply_failed', 0, 500),
            'processed_at' => now(),
        ]);
        if ($event) {
            app(FeishuProcessingReaction::class)->remove($event->fresh());
        }
    }
}
