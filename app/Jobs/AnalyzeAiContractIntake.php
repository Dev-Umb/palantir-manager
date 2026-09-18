<?php

namespace App\Jobs;

use App\Actions\ProcessAiContractIntake;
use App\Models\AiContractIntake;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalyzeAiContractIntake implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 200;

    public function __construct(public string $intakeId)
    {
        $this->onQueue('ai');
    }

    public function handle(ProcessAiContractIntake $processor): void
    {
        $intake = AiContractIntake::findOrFail($this->intakeId);
        if ($intake->status !== 'staged') {
            return;
        }
        $processor->analyze($intake, User::findOrFail($intake->user_id));
    }

    public function failed(?Throwable $exception): void
    {
        AiContractIntake::whereKey($this->intakeId)->whereIn('status', ['staged', 'analyzing'])->update(['status' => 'failed', 'error' => '识别超时或暂时不可用，文件已保留，可重试。']);
    }
}
