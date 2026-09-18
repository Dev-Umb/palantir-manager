<?php

namespace App\Actions;

use App\Jobs\CollectHubSource;
use App\Models\HubRun;
use App\Models\HubSource;
use App\Models\User;
use App\Support\HubSourceAdapters;
use Database\Seeders\HubSourceSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PrepareHubFeed
{
    public function sources(): void
    {
        app(HubSourceSeeder::class)->run();
        HubSource::where('enabled', true)->whereIn('adapter', HubSourceAdapters::SUPPORTED)->get()->each(function ($source): void {
            if ((! $source->last_checked_at || $source->last_checked_at->lte(now()->subMinutes($source->interval_minutes)))
                && ! HubRun::where('hub_source_id', $source->id)->whereIn('status', ['queued', 'running'])->exists()) {
                CollectHubSource::dispatch($source->id)->afterCommit();
            }
        });
    }

    public function research(User $user, Collection $notices, array $context): void
    {
        if (! empty($context['projects']) && ! config('procurement_hub.allow_project_ai', false)) {
            return;
        }
        DB::transaction(function () use ($user, $notices, $context): void {
            User::whereKey($user->id)->lockForUpdate()->first();
            $available = max(0, 3 - HubRun::where('user_id', $user->id)->where('kind', 'research')->whereIn('status', ['queued', 'running'])->count());
            foreach ($notices->take(3) as $notice) {
                if ($available === 0 || ! $notice->evidence()->exists()) {
                    continue;
                }
                $latest = HubRun::where('kind', 'research')->where('user_id', $user->id)->where('hub_notice_id', $notice->id)->latest('id')->first();
                if ($latest) {
                    if (in_array($latest->status, ['queued', 'running'], true) || $latest->cancelled_at) {
                        continue;
                    }
                    $current = ($latest->snapshot['project_version'] ?? null) === $context['project_version']
                        && ($latest->snapshot['notice_versions'][$notice->id] ?? null) === $notice->revision && ! $latest->stale_at;
                    if (($latest->status === 'published' && $current) || ($latest->status !== 'published' && $latest->created_at->gt(now()->subDay()))) {
                        continue;
                    }
                    if ($latest->published_at && ! $current) {
                        $latest->update(['stale_at' => now()]);
                    }
                }
                app(BuildHubResearch::class)->start($user->id, $notice->title, $notice->id);
                $available--;
            }
        });
    }
}
