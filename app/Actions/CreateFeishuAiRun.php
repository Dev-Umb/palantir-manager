<?php

namespace App\Actions;

use App\Ai\AiRunContextFactory;
use App\Ai\AiRunEventPublisher;
use App\Ai\AiRunRequestFingerprint;
use App\Jobs\RunAiHarness;
use App\Models\AiRun;
use App\Models\FeishuInboundEvent;
use App\Models\FeishuUserBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;

class CreateFeishuAiRun
{
    public function __construct(
        private ConversationStore $conversations,
        private AiRunRequestFingerprint $fingerprint,
        private AiRunContextFactory $contexts,
        private AiRunEventPublisher $events,
    ) {}

    public function handle(FeishuInboundEvent $event, FeishuUserBinding $binding, string $message): AiRun
    {
        $run = DB::transaction(function () use ($event, $binding, $message): AiRun {
            $conversationId = $binding->conversation_id;
            if (! $conversationId) {
                $conversationId = $this->conversations->storeConversation(
                    $binding->user_id,
                    Str::limit($message, 60),
                );
                $binding->update(['conversation_id' => $conversationId]);
            }

            $run = AiRun::create([
                'id' => (string) Str::uuid7(),
                'conversation_id' => $conversationId,
                'user_id' => $binding->user_id,
                'client_request_id' => (string) Str::uuid(),
                'request_hash' => $this->fingerprint->forRequest($message, $conversationId, null),
                'attempt_number' => 1,
                'status' => 'queued',
                'origin' => 'feishu',
                'channel_context' => ['inbound_event_id' => $event->id],
                'input' => $message,
                'context_snapshot' => $this->contexts->make($binding->user, $conversationId, null, 1),
                'artifacts' => [],
                'sources' => [],
                'provenance' => [],
                'data_quality' => [],
                'last_event_seq' => 0,
            ]);
            $event->update(['binding_id' => $binding->id, 'ai_run_id' => $run->id, 'status' => 'processing']);
            $this->events->publish($run, 'run.queued', ['message' => '任务已进入队列', 'attempt_number' => 1]);

            return $run;
        });

        RunAiHarness::dispatch($run->id)->afterCommit();

        return $run;
    }
}
