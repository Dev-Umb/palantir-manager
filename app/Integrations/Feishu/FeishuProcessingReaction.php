<?php

namespace App\Integrations\Feishu;

use App\Models\FeishuInboundEvent;
use Throwable;

class FeishuProcessingReaction
{
    public function __construct(private FeishuClient $client) {}

    public function add(FeishuInboundEvent $event): void
    {
        if ($event->processing_reaction_id || ! filled($event->message_id)) {
            return;
        }

        try {
            $result = $this->client->addReaction((string) $event->message_id);
            $event->update([
                'processing_reaction_id' => $result['reaction_id'],
                'processing_reaction_error' => null,
            ]);
        } catch (Throwable) {
            $event->update(['processing_reaction_error' => 'reaction_create_failed']);
        }
    }

    public function remove(FeishuInboundEvent $event): void
    {
        if (! $event->processing_reaction_id || ! $event->message_id || $event->processing_reaction_removed_at) {
            return;
        }

        try {
            $this->client->deleteReaction(
                (string) $event->message_id,
                (string) $event->processing_reaction_id,
            );
            $event->update([
                'processing_reaction_removed_at' => now(),
                'processing_reaction_error' => null,
            ]);
        } catch (Throwable) {
            $event->update(['processing_reaction_error' => 'reaction_delete_failed']);
        }
    }
}
