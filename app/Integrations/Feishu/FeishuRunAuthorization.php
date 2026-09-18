<?php

namespace App\Integrations\Feishu;

use App\Models\AiRun;
use App\Models\FeishuInboundEvent;
use App\Models\FeishuUserBinding;
use App\Models\User;

class FeishuRunAuthorization
{
    public function __construct(private FeishuRollout $rollout) {}

    public function resolveUser(AiRun $run): ?User
    {
        $eventId = filter_var(
            data_get($run->channel_context, 'inbound_event_id'),
            FILTER_VALIDATE_INT,
        );
        if (! $eventId) {
            return null;
        }

        $event = FeishuInboundEvent::query()
            ->whereKey($eventId)
            ->where('ai_run_id', $run->id)
            ->first();
        if (! $event || ! $event->binding_id || ! $this->rollout->allowsUser($run->user_id)) {
            return null;
        }

        $binding = FeishuUserBinding::query()
            ->with('user.roles.permissions')
            ->active()
            ->whereKey($event->binding_id)
            ->where('tenant_key', $event->tenant_key)
            ->where('open_id', $event->sender_open_id)
            ->where('user_id', $run->user_id)
            ->first();
        if (! $binding?->user || ! $binding->user->canDo('ai.harness.view')) {
            return null;
        }

        return $binding->user;
    }
}
