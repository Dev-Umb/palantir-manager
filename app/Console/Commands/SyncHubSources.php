<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncHubSources extends Command
{
    protected $signature = 'hub:sync {--seed}';

    protected $description = 'Retired procurement collector; use PC Codex publishing';

    public function handle(): int
    {
        $this->warn('旧招采自动采集已退役，请使用 PC Codex 发布。');

        return self::SUCCESS;
    }
}
