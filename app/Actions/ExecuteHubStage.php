<?php

namespace App\Actions;

use App\Ai\HubAgentRunner;
use App\Jobs\RunHubStage;
use App\Models\HubEvidence;
use App\Models\HubNotice;
use App\Models\HubRun;
use App\Models\HubSource;
use App\Models\HubStep;
use App\Support\HubEvidenceRules;
use App\Support\HubFetcher;
use App\Support\HubScreening;
use App\Support\HubSourceAdapters;
use App\Support\HubSourceExtraction;
use Illuminate\Support\Facades\DB;

class ExecuteHubStage
{
    public function __construct(private HubAgentRunner $agents, private BuildHubResearch $research, private HubEvidenceRules $rules, private HubFetcher $fetcher, private IngestHubNotice $ingest) {}

    public function handle(HubRun $run): void
    {
        $run->refresh();
        if ($run->cancelled_at || ! in_array($run->status, ['queued', 'running'], true)) {
            return;
        }
        if ($run->stage === 'gather') {
            $active = HubRun::where('snapshot->parent_run_id', $run->id)->whereIn('status', ['queued', 'running'])->exists();
            if ($active && $run->updated_at->gt(now()->subMinutes(20))) {
                RunHubStage::dispatch($run->id, 'gather', $run->round)->delay(now()->addSeconds(20));

                return;
            }
            $snapshot = $this->research->snapshot($run);
            if ($active) {
                $snapshot['limitations'][] = '部分来源仍在采集，本次只分析当前可用证据。';
            }
            $run->update(['snapshot' => $snapshot]);
            $this->next($run, 'analyze');

            return;
        }
        $step = HubStep::firstOrCreate(['hub_run_id' => $run->id, 'stage' => $run->stage, 'round' => $run->round], ['status' => 'queued']);
        if ($step->status === 'completed') {
            return;
        }
        $step->update(['status' => 'running', 'attempts' => $step->attempts + 1, 'error' => null]);
        $run->update(['status' => 'running', 'error' => null]);
        if ($run->stage === 'discover') {
            $this->discover($run, $step);

            return;
        }
        if ($run->stage === 'collect') {
            $this->collect($run, $step);

            return;
        }
        $snapshot = $run->snapshot ?: $this->research->snapshot($run);
        $run->update(['snapshot' => $snapshot]);
        $input = $snapshot;
        $input['previous'] = $run->steps()->where('status', 'completed')->where('round', $run->round)->get(['stage', 'output'])->toArray();
        $input['correction_issues'] = $run->audit['issues'] ?? [];
        $step->update(['input' => $input]);
        $preflightErrors = $run->stage === 'audit' ? [
            ...$this->rules->reportErrors($run->steps()->where('stage', 'analyze')->where('round', $run->round)->value('output') ?? [], $snapshot),
            ...$this->rules->reportErrors($run->result ?? [], $snapshot),
        ] : [];
        $response = $preflightErrors
            ? ['output' => ['decision' => 'revise', 'issues' => $preflightErrors, 'checked_evidence_ids' => []], 'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'preflight_rejected' => true]]
            : $this->agents->run($run->stage, $input);
        $output = $response['output'];
        DB::transaction(function () use ($run, $step, $snapshot, $output, $response): void {
            $locked = HubRun::lockForUpdate()->findOrFail($run->id);
            if ($locked->cancelled_at || $locked->stage !== $run->stage || $locked->round !== $run->round) {
                $step->update(['status' => 'cancelled']);

                return;
            }
            $step->update(['status' => 'completed', 'output' => $output, 'usage' => $response['usage']]);
            if ($run->stage === 'plan') {
                $sourceIds = array_slice(array_values(array_intersect($output['source_ids'] ?? [], $snapshot['source_ids'])), 0, 3);
                foreach ($sourceIds as $sourceId) {
                    $child = HubRun::create(['user_id' => $run->user_id, 'kind' => 'source', 'hub_source_id' => $sourceId,
                        'query' => $run->query, 'stage' => 'discover', 'snapshot' => ['parent_run_id' => $run->id]]);
                    RunHubStage::dispatch($child->id, 'discover', 0)->afterCommit();
                }
                $this->next($locked, $sourceIds ? 'gather' : 'analyze');
            } elseif ($run->stage === 'analyze') {
                $this->next($locked, 'recommend');
            } elseif ($run->stage === 'recommend') {
                $locked->update(['result' => $output]);
                $this->next($locked, 'audit');
            } else {
                $this->audit($locked, $step, $output, $snapshot);
            }
        });
    }

    private function audit(HubRun $run, HubStep $step, array $output, array $snapshot): void
    {
        $analysis = $run->steps()->where('stage', 'analyze')->where('round', $run->round)->value('output') ?? [];
        $recommendation = $run->result ?? [];
        $errors = [...$this->rules->reportErrors($analysis, $snapshot), ...$this->rules->reportErrors($recommendation, $snapshot)];
        $referenced = collect([...($analysis['claims'] ?? []), ...($recommendation['claims'] ?? [])])->flatMap(fn ($c) => $c['evidence_ids'] ?? [])->unique()->all();
        if (array_diff($referenced, $output['checked_evidence_ids'] ?? [])) {
            $errors[] = '审计未覆盖所有被引用的证据。';
        }
        foreach ($recommendation['project_references'] ?? [] as $reference) {
            $project = collect($snapshot['projects'] ?? [])->firstWhere('id', $reference['project_id'] ?? '');
            $value = $project[$reference['field'] ?? ''] ?? null;
            $quote = trim($reference['quote'] ?? '');
            if (! $project || ! is_scalar($value) || $quote === '' || ! str_contains($this->rules->normalize((string) $value), $this->rules->normalize($quote))) {
                $errors[] = '项目引用不属于授权主档或缺少原字段支持。';
            }
        }
        if (! $this->rules->snapshotCurrent($snapshot)) {
            $run->update(['status' => 'stale', 'stale_at' => now(), 'audit' => ['decision' => 'insufficient', 'issues' => ['分析期间输入资料已变化，请重新分析。']]]);

            return;
        }
        $issues = array_values(array_unique([...$errors, ...($output['issues'] ?? [])]));
        $decision = $errors || ($output['decision'] === 'pass' && $issues) ? 'revise' : $output['decision'];
        $run->update(['audit' => ['decision' => $decision, 'issues' => $issues, 'checked_evidence_ids' => $output['checked_evidence_ids'] ?? []]]);
        if ($decision === 'pass') {
            $profilePresent = ! empty($snapshot['projects'])
                && ! collect($snapshot['notices'])->contains(fn ($notice) => ! empty($notice['facts']['_conflicts']));
            $score = $profilePresent && ! empty($recommendation['project_references']) && ($recommendation['verdict'] ?? '') !== 'insufficient'
                ? ['value' => max(0, min(100, (int) ($recommendation['score'] ?? 0))), 'verdict' => $recommendation['verdict']] : null;
            $run->update(['status' => 'published', 'published_at' => now(), 'score' => $score,
                'result' => ['analysis' => $analysis, 'recommendation' => $score ? $recommendation : [...$recommendation, 'score' => 0, 'verdict' => 'insufficient'], 'statistics' => $snapshot['statistics'], 'limitations' => $snapshot['limitations']]]);
        } elseif ($decision === 'revise' && $run->round < (int) config('procurement_hub.max_corrections')) {
            $run->update(['round' => $run->round + 1]);
            $run->update(['snapshot' => $this->research->snapshot($run)]);
            $this->next($run, 'plan');
        } else {
            $run->update(['status' => 'insufficient']);
        }
    }

    private function discover(HubRun $run, HubStep $step): void
    {
        $source = HubSource::findOrFail($run->hub_source_id);
        if (! $source->enabled || ! in_array($source->adapter, HubSourceAdapters::SUPPORTED, true)) {
            $run->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $step->update(['status' => 'cancelled']);

            return;
        }
        $document = app(HubSourceAdapters::class)->discover($source);
        foreach ($document['candidate_sources'] ?? [] as $candidate) {
            HubSource::firstOrCreate(['url' => $candidate['url']], [...$candidate, 'enabled' => false, 'adapter' => 'unverified', 'status' => 'candidate']);
        }
        $keywords = $source->keywords ?: config('procurement_hub.keywords');
        $allLinks = collect($document['links']);
        $historyPages = $allLinks->filter(fn ($link) => mb_strlen($link['title']) <= 18 && preg_match('/招标采购|非招采购|中标|成交|结果公示|下一页|更多/u', $link['title']))
            ->unique('url')->sortByDesc(fn ($link) => preg_match('/招标采购|非招采购/u', $link['title']))->take(2);
        $coverage = $document['coverage'] ?? [];
        foreach ($historyPages as $page) {
            try {
                $history = $this->fetcher->fetch($source, $page['url'], 8);
                $allLinks = $allLinks->concat($history['links']);
                $coverage[] = ['url' => $page['url'], 'status' => 'fetched'];
            } catch (\Throwable) {
                $coverage[] = ['url' => $page['url'], 'status' => 'unavailable'];
            }
        }
        $links = $allLinks->filter(fn ($link) => mb_strlen($link['title']) > 18 && ! preg_match('/测试|注册|通知|模板下载/u', $link['title']) && collect($keywords)->contains(fn ($word) => app(HubScreening::class)->matches($link['title'].' '.($link['document']['extracted']['procurement_content'] ?? ''), $word)))
            ->unique('url')->sortByDesc(fn ($link) => $run->query && str_contains($link['title'], $run->query) ? 1 : 0)->take(config('procurement_hub.max_pages'));
        foreach ($links as $link) {
            if ($run->fresh()->cancelled_at) {
                return;
            }
            $child = HubRun::where('kind', 'collect')->where('hub_source_id', $source->id)->where('snapshot->url', $link['url'])->whereIn('status', ['queued', 'running'])->first();
            if ($child) {
                continue;
            }
            $child = HubRun::create(['kind' => 'collect', 'hub_source_id' => $source->id, 'query' => mb_substr($link['title'], 0, 500),
                'stage' => 'collect', 'user_id' => $run->user_id, 'snapshot' => ['url' => $link['url'], 'document' => $link['document'] ?? null, 'detail_url' => $link['detail_url'] ?? null, 'parent_run_id' => $run->snapshot['parent_run_id'] ?? $run->id]]);
            RunHubStage::dispatch($child->id, 'collect', 0);
        }
        $source->update(['last_checked_at' => now(), 'last_success_at' => now(), 'status' => $links->isEmpty() ? 'needs_review' : 'active', 'last_error' => $links->isEmpty() ? '页面可访问，但未发现符合条件的公告链接。' : null]);
        $step->update(['status' => 'completed', 'output' => ['links' => $links->values()->all(), 'content_hash' => $document['content_hash'], 'history_pages' => $coverage, 'limit' => config('procurement_hub.max_pages')]]);
        $run->update(['status' => 'completed', 'result' => ['discovered' => $links->count()]]);
    }

    private function collect(HubRun $run, HubStep $step): void
    {
        $source = HubSource::findOrFail($run->hub_source_id);
        if (! $source->enabled || ! in_array($source->adapter, HubSourceAdapters::SUPPORTED, true)) {
            $run->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $step->update(['status' => 'cancelled']);

            return;
        }
        $document = app(HubSourceAdapters::class)->document($source, $run->snapshot);
        if (HubEvidence::where('url_hash', hash('sha256', $document['url']))->where('content_hash', $document['content_hash'])->exists()) {
            DB::transaction(function () use ($run, $step): void {
                $locked = HubRun::lockForUpdate()->findOrFail($run->id);
                if ($locked->cancelled_at) {
                    $step->update(['status' => 'cancelled']);

                    return;
                }
                $step->update(['status' => 'completed', 'output' => ['unchanged' => true]]);
                $locked->update(['status' => 'completed', 'result' => ['unchanged' => true]]);
            });

            return;
        }
        $step->update(['input' => $document]);
        $basic = $document['extracted'] ?? app(HubSourceExtraction::class)->extract($source, $document, $run->query);
        $response = $basic ? ['output' => $basic, 'usage' => []] : $this->agents->run('collect', $document);
        $notice = DB::transaction(function () use ($run, $source, $step, $document, $response): ?HubNotice {
            $locked = HubRun::lockForUpdate()->findOrFail($run->id);
            $currentSource = HubSource::lockForUpdate()->findOrFail($source->id);
            $parentId = $run->snapshot['parent_run_id'] ?? null;
            if ($locked->cancelled_at || ! $currentSource->enabled || ($parentId && HubRun::find($parentId)?->cancelled_at)) {
                $step->update(['status' => 'cancelled']);
                $locked->update(['status' => 'cancelled', 'cancelled_at' => now()]);

                return null;
            }
            if (! ($response['output']['is_notice'] ?? false)) {
                $step->update(['status' => 'completed', 'output' => $response['output'], 'usage' => $response['usage']]);
                $locked->update(['status' => 'insufficient', 'error' => '该页面未识别为采购公告。']);

                return null;
            }
            $notice = $this->ingest->handle($currentSource, $document, $response['output']);
            $step->update(['status' => 'completed', 'output' => $response['output'], 'usage' => $response['usage']]);
            $locked->update(['status' => 'completed', 'hub_notice_id' => $notice->id, 'result' => ['notice_id' => $notice->id]]);

            return $notice;
        });
        if (! $notice) {
            return;
        }
        if (! HubRun::where('kind', 'research')->whereNull('user_id')->where('hub_notice_id', $notice->id)->whereIn('status', ['queued', 'running'])->exists()) {
            $this->research->start(null, $notice->title, $notice->id);
        }
    }

    private function next(HubRun $run, string $stage): void
    {
        $run->update(['stage' => $stage, 'status' => 'queued']);
        RunHubStage::dispatch($run->id, $stage, $run->round)->afterCommit();
    }
}
