<?php

namespace App\Jobs;

use App\Actions\ExecuteHubStage;
use App\Models\HubRun;
use App\Models\HubSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class RunHubStage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 80;

    public function __construct(public int $runId, public string $stage, public int $round)
    {
        $this->onQueue(config('procurement_hub.queue'));
        $this->onConnection(config('procurement_hub.connection', 'database'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('hub-run:'.$this->runId))->releaseAfter(10)->expireAfter(85)];
    }

    public function backoff(): array
    {
        return [30, 60];
    }

    public function handle(ExecuteHubStage $execute): void
    {
        $run = HubRun::find($this->runId);
        if (! $run || $run->cancelled_at || $run->stage !== $this->stage || (int) $run->round !== $this->round
            || ! in_array($run->status, ['queued', 'running'], true)) {
            return;
        }
        $parent = $run->snapshot['parent_run_id'] ?? null;
        if ($parent && HubRun::find($parent)?->cancelled_at) {
            $run->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return;
        }
        try {
            $execute->handle($run);
        } catch (Throwable $exception) {
            $run->steps()->where('stage', $this->stage)->where('round', $this->round)->update(['status' => 'failed', 'error' => '执行失败：'.class_basename($exception)]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = HubRun::find($this->runId);
        if ($run && ! $run->cancelled_at && ! in_array($run->status, ['published', 'completed'], true)) {
            $run->update(['status' => 'failed', 'error' => '执行暂时失败，请检查来源可用性及模型连接后重试。']);
            if ($run->hub_source_id) {
                HubSource::whereKey($run->hub_source_id)->update(['last_checked_at' => now(), 'status' => 'error', 'last_error' => '采集失败，已有数据保留。']);
            }
        }
    }
}
