<?php

namespace App\Jobs;

use App\Ai\AiRunEventPublisher;
use App\Ai\AiToolEventProjector;
use App\Ai\XycDataAgent;
use App\Models\AiRun;
use App\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Laravel\Ai\Streaming\Events\Error as ErrorEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use RuntimeException;
use Throwable;

class RunAiHarness implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 210;

    public function __construct(public string $runId)
    {
        $this->onQueue('ai');
    }

    public function middleware(): array
    {
        $conversationId = AiRun::whereKey($this->runId)->value('conversation_id') ?: $this->runId;

        return [(new WithoutOverlapping("ai-conversation:{$conversationId}"))->releaseAfter(2)->expireAfter(240)];
    }

    public function backoff(): array
    {
        return [1, 3];
    }

    public function handle(AiRunEventPublisher $events, AiToolEventProjector $tools): void
    {
        $run = AiRun::with('user')->findOrFail($this->runId);
        if (in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $run->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'error' => null,
        ]);
        $events->publish($run, 'run.started', ['message' => '任务开始执行']);
        $events->publish($run, 'activity.updated', [
            'label' => '正在理解问题',
            'status' => 'running',
        ]);

        $answer = '';
        $buffer = '';
        $lastFlush = microtime(true);
        $lastCancellationCheck = null;
        $textDeltasSinceCancellationCheck = 0;

        $flush = function () use (&$buffer, &$lastFlush, $events, $run): void {
            if ($buffer === '') {
                return;
            }

            $events->publish($run, 'answer.delta', ['delta' => $buffer]);
            $buffer = '';
            $lastFlush = microtime(true);
        };

        try {
            $agent = XycDataAgent::make(user: $run->user)
                ->continue($run->conversation_id, $run->user);
            $stream = $agent->stream(
                $run->input,
                provider: config('ai.default', 'ark'),
                timeout: (int) config('ai.request_timeout', 180),
            );

            foreach ($stream as $event) {
                $cancelCheckable = $event instanceof TextDelta || $event instanceof ToolCallEvent || $event instanceof ToolResultEvent;
                $toolBoundary = $event instanceof ToolCallEvent || $event instanceof ToolResultEvent;
                if ($event instanceof TextDelta) {
                    $textDeltasSinceCancellationCheck++;
                }

                $periodicTextCheck = $textDeltasSinceCancellationCheck >= 8
                    && microtime(true) - ($lastCancellationCheck ?? 0) >= 0.5;
                if ($cancelCheckable && ($lastCancellationCheck === null || $toolBoundary || $periodicTextCheck)) {
                    $cancelRequested = AiRun::whereKey($run->id)->whereNotNull('cancel_requested_at')->exists();
                    $lastCancellationCheck = microtime(true);
                    $textDeltasSinceCancellationCheck = 0;

                    if ($cancelRequested) {
                        $this->cancel($run, $events);

                        return;
                    }
                }

                if ($event instanceof ErrorEvent) {
                    throw new RuntimeException($event->message);
                }

                if ($event instanceof ToolCallEvent) {
                    $flush();
                    $tools->started($run, $event->toolCall);

                    continue;
                }

                if ($event instanceof ToolResultEvent) {
                    $tools->completed($run, $event->toolResult);

                    continue;
                }

                if (! $event instanceof TextDelta) {
                    continue;
                }

                $answer .= $event->delta;
                $buffer .= $event->delta;
                if (strlen($buffer) >= 256 || microtime(true) - $lastFlush >= 0.075) {
                    $flush();
                }
            }

            $flush();

            if (AiRun::whereKey($run->id)->whereNotNull('cancel_requested_at')->exists()) {
                $this->cancel($run, $events);

                return;
            }

            $run->update([
                'status' => 'completed',
                'answer' => $answer,
                'usage' => json_decode(json_encode($stream->usage), true) ?: [],
                'finished_at' => now(),
            ]);
            $events->publish($run, 'run.completed', [
                'message' => '分析完成',
                'answer_length' => mb_strlen($answer),
            ]);

            $this->audit($run, 'ai.run.completed');
        } catch (Throwable $exception) {
            report($exception);
            if ($answer === '' && $this->attempts() < $this->tries) {
                $attempt = $this->attempts();
                $run->update([
                    'status' => 'queued',
                    'artifacts' => [],
                    'sources' => [],
                    'data_quality' => [],
                    'error' => null,
                ]);
                $events->publish($run, 'run.retrying', [
                    'label' => 'AI 服务暂时不可用，正在重试',
                    'status' => 'running',
                    'attempt' => $attempt + 1,
                ]);
                $this->release($this->backoff()[$attempt - 1] ?? 3);

                return;
            }

            $run->update([
                'status' => 'failed',
                'error' => [
                    'code' => 'provider_error',
                    'message' => 'AI 服务调用失败，请稍后重试。',
                    'recoverable' => true,
                ],
                'finished_at' => now(),
            ]);
            $events->publish($run, 'run.failed', $run->error);
            $this->audit($run, 'ai.run.failed');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = AiRun::find($this->runId);
        if (! $run || in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $run->update([
            'status' => 'failed',
            'error' => [
                'code' => 'worker_failed',
                'message' => 'AI 任务执行超时或工作进程异常，请重试。',
                'recoverable' => true,
            ],
            'finished_at' => now(),
        ]);

        try {
            app(AiRunEventPublisher::class)->publish($run, 'run.failed', $run->error);
        } catch (Throwable $publishError) {
            report($publishError);
        }

        $this->audit($run, 'ai.run.failed');
    }

    private function audit(AiRun $run, string $action): void
    {
        AuditLog::create([
            'user_id' => $run->user_id,
            'action' => $action,
            'subject_type' => 'ai_run',
            'subject_id' => $run->id,
            'payload' => [
                'status' => $run->status,
                'last_event_seq' => $run->last_event_seq,
                'duration_ms' => $run->started_at && $run->finished_at
                    ? (int) $run->started_at->diffInMilliseconds($run->finished_at)
                    : null,
                'sources' => collect($run->sources ?? [])->map(fn (array $source) => [
                    'object_key' => $source['object_key'] ?? null,
                    'record_count' => $source['record_count'] ?? 0,
                ])->values()->all(),
                'artifact_types' => collect($run->artifacts ?? [])->pluck('type')->values()->all(),
                'usage' => $run->usage ?? [],
            ],
        ]);
    }

    private function cancel(AiRun $run, AiRunEventPublisher $events): void
    {
        $run->update([
            'status' => 'cancelled',
            'finished_at' => now(),
        ]);
        $events->publish($run, 'run.cancelled', ['message' => '任务已停止']);
        $this->audit($run, 'ai.run.cancelled');
    }
}
