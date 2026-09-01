<?php

namespace App\Jobs;

use App\Integrations\Feishu\FeishuClient;
use App\Integrations\Feishu\FeishuMessageRenderer;
use App\Integrations\Feishu\FeishuProcessingReaction;
use App\Models\AiRun;
use App\Models\FeishuInboundEvent;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendFeishuAiReply implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $runId)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return $this->runId;
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(
        FeishuClient $client,
        FeishuMessageRenderer $renderer,
        FeishuProcessingReaction $reaction,
    ): void {
        $run = AiRun::findOrFail($this->runId);
        $event = FeishuInboundEvent::findOrFail((int) data_get($run->channel_context, 'inbound_event_id'));
        if ($event->reply_message_id || ! in_array($run->status, ['completed', 'failed'], true)) {
            return;
        }

        $isGroup = data_get($event->payload, 'event.message.chat_type') === 'group';
        $chatId = (string) data_get($event->payload, 'event.message.chat_id');
        if ($isGroup && $chatId === '') {
            throw new \RuntimeException('feishu_group_chat_id_missing');
        }

        if ($run->status === 'completed') {
            $card = $renderer->renderAiReplyCard((string) $run->answer);
            $result = $isGroup
                ? $client->sendCardToChat($chatId, $card)
                : $client->sendCard((string) $event->sender_open_id, $card);
        } else {
            $failureMessage = '本次查询暂时失败，请稍后重试。';
            $result = $isGroup
                ? $client->sendTextToChat($chatId, $failureMessage)
                : $client->sendText((string) $event->sender_open_id, $failureMessage);
        }
        $event->update([
            'status' => 'completed',
            'reply_message_id' => $result['message_id'],
            'error' => null,
            'processed_at' => now(),
        ]);
        $reaction->remove($event->fresh());
    }

    public function failed(?Throwable $exception): void
    {
        $run = AiRun::find($this->runId);
        $eventId = (int) data_get($run?->channel_context, 'inbound_event_id');
        if ($eventId > 0) {
            $event = FeishuInboundEvent::find($eventId);
            $event?->update([
                'status' => 'failed',
                'error' => mb_substr($exception?->getMessage() ?: 'reply_failed', 0, 500),
                'processed_at' => now(),
            ]);
            if ($event) {
                app(FeishuProcessingReaction::class)->remove($event->fresh());
            }
        }
    }
}
