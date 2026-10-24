<?php

namespace App\Jobs;

use App\Models\HubSource;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class CollectHubSource implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public int $timeout = 30;

    public function __construct(public int $sourceId)
    {
        $this->onQueue(config('procurement_hub.queue'));
        $this->onConnection(config('procurement_hub.connection', 'database'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('hub-source:'.$this->sourceId))->releaseAfter(30)->expireAfter(60)];
    }

    public function handle(): void
    {
        // Already queued legacy work is intentionally discarded.
    }

    public function failed(?Throwable $exception): void
    {
        HubSource::whereKey($this->sourceId)->update(['status' => 'error', 'last_error' => '采集任务派发失败。']);
    }
}
