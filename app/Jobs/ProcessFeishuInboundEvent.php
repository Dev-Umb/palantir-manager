<?php

namespace App\Jobs;

use App\Actions\CreateFeishuAiRun;
use App\Integrations\Feishu\FeishuClient;
use App\Integrations\Feishu\FeishuContractAttachmentService;
use App\Integrations\Feishu\FeishuProcessingReaction;
use App\Models\FeishuInboundEvent;
use App\Models\FeishuUserBinding;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class ProcessFeishuInboundEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $eventId)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return (string) $this->eventId;
    }

    public function handle(CreateFeishuAiRun $runs, FeishuProcessingReaction $reaction): void
    {
        $inbound = FeishuInboundEvent::findOrFail($this->eventId);
        if ($inbound->status !== 'received') {
            return;
        }

        $payload = $inbound->payload;
        $event = data_get($payload, 'event', []);
        $chatType = (string) data_get($event, 'message.chat_type');
        $mentionKeys = collect(data_get($event, 'message.mentions', []))
            ->pluck('key')
            ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
            ->values();
        $isSupportedChat = $chatType === 'p2p'
            || ($chatType === 'group'
                && $mentionKeys->isNotEmpty()
                && filled(data_get($event, 'message.chat_id')));
        $messageType = (string) data_get($event, 'message.message_type');
        $isSupportedMessage = $isSupportedChat
            && in_array($messageType, ['text', 'file'], true)
            && data_get($event, 'sender.sender_type', 'user') === 'user';
        if (! $isSupportedMessage) {
            $inbound->update(['status' => 'ignored', 'processed_at' => now()]);

            return;
        }

        $binding = FeishuUserBinding::with('user.roles.permissions')->active()
            ->where('tenant_key', $inbound->tenant_key)
            ->where('open_id', $inbound->sender_open_id)
            ->first();
        if (! $binding || ! $binding->user->canDo('ai.harness.view')) {
            $inbound->update(['status' => 'rejected', 'error' => $binding ? 'permission_denied' : 'user_not_bound', 'processed_at' => now()]);

            return;
        }

        $content = json_decode((string) data_get($event, 'message.content', '{}'), true);
        if (! is_array($content)) {
            $inbound->update(['status' => 'ignored', 'processed_at' => now()]);

            return;
        }

        if ($messageType === 'file') {
            if (! $binding->user->canDo('object.project.update')) {
                $inbound->update(['status' => 'rejected', 'error' => 'permission_denied', 'processed_at' => now()]);

                return;
            }
            $reaction->add($inbound);
            $card = app(FeishuContractAttachmentService::class)->stage($inbound, $binding, $content);
            $reply = $this->sendCard($inbound, app(FeishuClient::class), $card);
            $inbound->update(['status' => 'completed', 'reply_message_id' => $reply['message_id'], 'processed_at' => now()]);
            $reaction->remove($inbound->fresh());

            return;
        }

        $message = Str::of((string) ($content['text'] ?? ''))
            ->when($chatType === 'group', fn ($text) => $text->replace($mentionKeys->all(), ' '))
            ->squish()
            ->toString();
        if ($message === '') {
            $inbound->update(['status' => 'ignored', 'processed_at' => now()]);

            return;
        }

        $reaction->add($inbound);
        $attachments = app(FeishuContractAttachmentService::class);
        if ($attachments->isBindingCommand($message)) {
            $card = $attachments->bind($inbound, $binding, $message);
            $reply = $this->sendCard($inbound, app(FeishuClient::class), $card);
            $inbound->update(['status' => 'completed', 'reply_message_id' => $reply['message_id'], 'processed_at' => now()]);
            $reaction->remove($inbound->fresh());

            return;
        }

        $runs->handle($inbound, $binding, $message);
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array{message_id: string}
     */
    private function sendCard(FeishuInboundEvent $event, FeishuClient $client, array $card): array
    {
        $chatType = (string) data_get($event->payload, 'event.message.chat_type');
        if ($chatType === 'group') {
            return $client->sendCardToChat((string) data_get($event->payload, 'event.message.chat_id'), $card);
        }

        return $client->sendCard((string) $event->sender_open_id, $card);
    }

    public function failed(?Throwable $exception): void
    {
        $event = FeishuInboundEvent::find($this->eventId);
        $event?->update([
            'status' => 'failed',
            'error' => mb_substr($exception?->getMessage() ?: 'processing_failed', 0, 500),
            'processed_at' => now(),
        ]);
        if ($event) {
            app(FeishuProcessingReaction::class)->remove($event->fresh());
        }
    }
}
