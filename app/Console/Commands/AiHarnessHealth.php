<?php

namespace App\Console\Commands;

use App\Models\AiRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiHarnessHealth extends Command
{
    protected $signature = 'ai:harness-health';

    protected $description = 'Report AI queue backlog, failures, and stalled runs';

    public function handle(): int
    {
        $since = now()->subDay();
        $total = AiRun::where('created_at', '>=', $since)->count();
        $failed = AiRun::where('created_at', '>=', $since)->where('status', 'failed')->count();
        $stalled = AiRun::where('status', 'running')
            ->where('started_at', '<', now()->subSeconds(180))
            ->count();
        $backlog = DB::table('jobs')->where('queue', 'ai')->whereNull('reserved_at')->count();
        $oldestQueuedAt = DB::table('jobs')->where('queue', 'ai')->whereNull('reserved_at')->min('available_at');

        $report = [
            'status' => $stalled > 0 ? 'degraded' : 'ok',
            'queue_backlog' => $backlog,
            'oldest_queued_seconds' => $oldestQueuedAt ? max(0, now()->timestamp - (int) $oldestQueuedAt) : 0,
            'runs_24h' => $total,
            'failed_24h' => $failed,
            'failure_rate' => $total > 0 ? round($failed / $total, 4) : 0,
            'stalled_over_180s' => $stalled,
            'checked_at' => now()->toISOString(),
        ];

        $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $stalled > 0 ? self::FAILURE : self::SUCCESS;
    }
}
