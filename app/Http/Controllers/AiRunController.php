<?php

namespace App\Http\Controllers;

use App\Ai\AiRunContextFactory;
use App\Ai\AiRunEventPublisher;
use App\Ai\AiRunRequestFingerprint;
use App\Http\Requests\CancelAiRunRequest;
use App\Http\Requests\StoreAiRunRequest;
use App\Jobs\RunAiHarness;
use App\Models\AiRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;

class AiRunController extends Controller
{
    public function store(
        StoreAiRunRequest $request,
        AiRunEventPublisher $events,
        ConversationStore $conversations,
        AiRunRequestFingerprint $fingerprint,
        AiRunContextFactory $contexts,
    ): JsonResponse {
        $this->assertHarnessEnabled();

        $data = $request->validated();
        $retryParent = null;
        if (filled($data['retry_parent_id'] ?? null)) {
            $retryParent = AiRun::query()
                ->whereKey($data['retry_parent_id'])
                ->where('user_id', $request->user()->id)
                ->firstOrFail();
            if (in_array($retryParent->status, ['queued', 'running'], true)) {
                return response()->json([
                    'message' => '原任务仍在执行，不能创建重试任务。',
                    'code' => 'retry_parent_active',
                ], 409);
            }
            if (filled($data['conversation_id'] ?? null) && $data['conversation_id'] !== $retryParent->conversation_id) {
                return response()->json([
                    'message' => '重试任务必须沿用原任务的对话。',
                    'code' => 'retry_conversation_mismatch',
                ], 422);
            }
            $data['conversation_id'] = $retryParent->conversation_id;
        }

        if (filled($data['conversation_id'] ?? null)) {
            abort_unless(
                Conversation::whereKey($data['conversation_id'])->where('user_id', $request->user()->id)->exists(),
                403,
            );
        }

        $requestHash = $fingerprint->forRequest(
            $data['message'],
            $data['conversation_id'] ?? null,
            $retryParent?->id,
        );
        $attemptNumber = $retryParent ? ((int) $retryParent->attempt_number + 1) : 1;

        [$run, $created, $conflict] = DB::transaction(function () use (
            $data, $request, $events, $conversations, $contexts, $requestHash, $retryParent, $attemptNumber,
        ) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [
                    "ai-run:{$request->user()->id}:{$data['client_request_id']}",
                ]);
            }

            $existing = AiRun::query()
                ->where('user_id', $request->user()->id)
                ->where('client_request_id', $data['client_request_id'])
                ->first();
            if ($existing) {
                return [$existing, false, ! hash_equals((string) $existing->request_hash, $requestHash)];
            }

            $conversationId = $data['conversation_id'] ?? $conversations->storeConversation(
                $request->user()->id,
                Str::limit($data['message'], 60),
            );
            $run = AiRun::create([
                'id' => (string) Str::uuid7(),
                'conversation_id' => $conversationId,
                'user_id' => $request->user()->id,
                'client_request_id' => $data['client_request_id'],
                'request_hash' => $requestHash,
                'retry_parent_id' => $retryParent?->id,
                'attempt_number' => $attemptNumber,
                'status' => 'queued',
                'input' => $data['message'],
                'context_snapshot' => $contexts->make(
                    $request->user(), $conversationId, $retryParent?->id, $attemptNumber,
                ),
                'artifacts' => [],
                'sources' => [],
                'provenance' => [],
                'last_event_seq' => 0,
            ]);

            $events->publish($run, 'run.queued', [
                'message' => '任务已进入队列',
                'attempt_number' => $attemptNumber,
                'retry_parent_id' => $retryParent?->id,
            ]);

            return [$run, true, false];
        });

        if ($conflict) {
            return response()->json([
                'message' => '该 client_request_id 已用于不同请求。',
                'code' => 'idempotency_conflict',
                'run_id' => $run->id,
            ], 409);
        }

        if ($created) {
            RunAiHarness::dispatch($run->id);
        }

        return $this->createdResponse($run);
    }

    public function show(Request $request, AiRun $run): JsonResponse
    {
        $this->assertHarnessEnabled();
        $this->authorizeRun($request, $run);

        return response()->json(['run' => $run->snapshot()]);
    }

    public function events(Request $request, AiRun $run): JsonResponse
    {
        $this->assertHarnessEnabled();
        $this->authorizeRun($request, $run);

        $after = max(0, (int) $request->integer('after', 0));
        $items = $run->events()->where('seq', '>', $after)->limit(500)->get();

        return response()->json([
            'events' => $items->map->envelope()->values()->all(),
            'last_event_seq' => max((int) $run->last_event_seq, (int) ($items->last()?->seq ?? 0)),
        ]);
    }

    public function cancel(CancelAiRunRequest $request, AiRun $run, AiRunEventPublisher $events): JsonResponse
    {
        $this->assertHarnessEnabled();
        $this->authorizeRun($request, $run);

        $reason = $request->validated('reason') ?: 'user_request';
        $cancelled = DB::transaction(function () use ($run, $reason) {
            $locked = AiRun::query()->lockForUpdate()->findOrFail($run->id);
            if (in_array($locked->status, ['completed', 'failed', 'cancelled'], true)) {
                return false;
            }

            $locked->cancel_requested_at = now();
            $locked->cancel_reason = $locked->cancel_reason ?: $reason;
            if ($locked->status === 'queued') {
                $locked->status = 'cancelled';
                $locked->finished_at = now();
            }
            $locked->save();

            return $locked->status === 'cancelled';
        });

        $run->refresh();
        if ($cancelled) {
            $events->publish($run, 'run.cancelled', [
                'message' => '任务已停止',
                'reason' => $run->cancel_reason,
            ]);
        } elseif ($run->status === 'running') {
            $events->publish($run, 'activity.updated', [
                'label' => '正在停止任务',
                'status' => 'running',
            ]);
        }

        return response()->json(['run' => $run->refresh()->snapshot()]);
    }

    private function createdResponse(AiRun $run): JsonResponse
    {
        return response()->json([
            'conversation_id' => $run->conversation_id,
            'channel' => "private-ai.runs.{$run->id}",
            'run' => $run->snapshot(),
        ], 202);
    }

    private function authorizeRun(Request $request, AiRun $run): void
    {
        abort_unless($run->user_id === $request->user()->id, 403);
    }

    private function assertHarnessEnabled(): void
    {
        abort_unless(config('ai.harness_v2'), 404);
    }
}
