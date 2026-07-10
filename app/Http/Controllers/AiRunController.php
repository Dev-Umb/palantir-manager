<?php

namespace App\Http\Controllers;

use App\Ai\AiRunEventPublisher;
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
    public function store(Request $request, AiRunEventPublisher $events, ConversationStore $conversations): JsonResponse
    {
        $this->assertHarnessEnabled();

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'string', 'max:36'],
            'client_request_id' => ['required', 'uuid'],
        ]);

        if (filled($data['conversation_id'] ?? null)) {
            abort_unless(
                Conversation::whereKey($data['conversation_id'])->where('user_id', $request->user()->id)->exists(),
                403,
            );
        }

        [$run, $created] = DB::transaction(function () use ($data, $request, $events, $conversations) {
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
                return [$existing, false];
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
                'status' => 'queued',
                'input' => $data['message'],
                'artifacts' => [],
                'sources' => [],
                'last_event_seq' => 0,
            ]);

            $events->publish($run, 'run.queued', ['message' => '任务已进入队列']);

            return [$run, true];
        });

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

    public function cancel(Request $request, AiRun $run, AiRunEventPublisher $events): JsonResponse
    {
        $this->assertHarnessEnabled();
        $this->authorizeRun($request, $run);

        $cancelled = DB::transaction(function () use ($run) {
            $locked = AiRun::query()->lockForUpdate()->findOrFail($run->id);
            if (in_array($locked->status, ['completed', 'failed', 'cancelled'], true)) {
                return false;
            }

            $locked->cancel_requested_at = now();
            if ($locked->status === 'queued') {
                $locked->status = 'cancelled';
                $locked->finished_at = now();
            }
            $locked->save();

            return $locked->status === 'cancelled';
        });

        $run->refresh();
        if ($cancelled) {
            $events->publish($run, 'run.cancelled', ['message' => '任务已停止']);
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
