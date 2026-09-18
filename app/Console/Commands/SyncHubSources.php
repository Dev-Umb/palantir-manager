<?php

namespace App\Console\Commands;

use App\Actions\BuildHubResearch;
use App\Jobs\CollectHubSource;
use App\Jobs\RunHubStage;
use App\Models\HubRun;
use App\Models\HubSource;
use App\Support\HubSourceAdapters;
use Database\Seeders\HubSourceSeeder;
use Illuminate\Console\Command;

class SyncHubSources extends Command
{
    protected $signature = 'hub:sync {--seed : Initialize verified default and disabled candidate sources}';

    protected $description = 'Automatically initialize and dispatch due procurement information sources';

    public function handle(): int
    {
        app(HubSourceSeeder::class)->run();
        $count = 0;
        HubSource::where('enabled', true)->whereIn('adapter', HubSourceAdapters::SUPPORTED)->each(function (HubSource $source) use (&$count): void {
            if (! $source->last_checked_at || $source->last_checked_at->lte(now()->subMinutes($source->interval_minutes))) {
                CollectHubSource::dispatch($source->id);
                $count++;
            }
        });
        HubRun::whereIn('status', ['queued', 'running'])->whereNull('cancelled_at')->where('updated_at', '<', now()->subMinutes(3))
            ->limit(100)->each(fn ($run) => RunHubStage::dispatch($run->id, $run->stage, $run->round));
        HubRun::where('kind', 'research')->where('status', 'published')->whereNotNull('stale_at')->latest('id')->limit(25)->get()
            ->unique(fn ($run) => $run->user_id.'|'.$run->query.'|'.$run->hub_notice_id)->each(function (HubRun $run): void {
                $replacement = HubRun::where('kind', 'research')->where('user_id', $run->user_id)->where('query', $run->query)
                    ->where('hub_notice_id', $run->hub_notice_id)->where('id', '>', $run->id)
                    ->where(fn ($q) => $q->whereIn('status', ['queued', 'running'])->orWhere('created_at', '>=', $run->stale_at))->exists();
                if (! $replacement) {
                    app(BuildHubResearch::class)->start($run->user_id, $run->query, $run->hub_notice_id);
                }
            });
        $this->info('已派发 '.$count.' 个到期来源。');

        return self::SUCCESS;
    }
}
