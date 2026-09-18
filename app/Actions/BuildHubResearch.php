<?php

namespace App\Actions;

use App\Jobs\RunHubStage;
use App\Models\HubEvidence;
use App\Models\HubNotice;
use App\Models\HubRun;
use App\Models\HubSource;
use App\Models\User;
use App\Support\HubEvidenceRules;
use App\Support\HubProjectContext;
use App\Support\HubScreening;
use Illuminate\Database\Eloquent\Builder;

class BuildHubResearch
{
    public function __construct(private HubEvidenceRules $rules) {}

    public function start(?int $userId, string $query, ?int $noticeId = null): HubRun
    {
        $run = HubRun::create(['user_id' => $userId, 'hub_notice_id' => $noticeId, 'kind' => 'research', 'query' => $query, 'stage' => 'plan']);
        RunHubStage::dispatch($run->id, 'plan', 0)->afterCommit();

        return $run;
    }

    public function snapshot(HubRun $run): array
    {
        $query = HubNotice::query();
        if ($run->hub_notice_id) {
            $target = HubNotice::findOrFail($run->hub_notice_id);
            $query->where(function (Builder $q) use ($target): void {
                $q->where('id', $target->id)->orWhere('project_key', $target->project_key);
                if ($target->buyer) {
                    $q->orWhere('buyer', $target->buyer);
                }
                if ($target->product) {
                    $q->orWhere('product', $target->product);
                }
            });
        } elseif ($run->query !== '') {
            $term = '%'.addcslashes($run->query, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('title', 'like', $term)->orWhere('buyer', 'like', $term)->orWhere('group_name', 'like', $term)->orWhere('product', 'like', $term));
        }
        $query->when($run->hub_notice_id, fn ($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$run->hub_notice_id]));
        $notices = $query->latest('published_at')->limit(config('procurement_hub.sample_limit'))->get();
        $evidence = HubEvidence::whereIn('hub_notice_id', $notices->pluck('id'))->orderByDesc('id')->get()->unique('hub_notice_id')->sortBy(fn ($e) => $e->hub_notice_id === $run->hub_notice_id ? 0 : 1)->take(config('procurement_hub.evidence_limit'))->values();
        $notices = $notices->whereIn('id', $evidence->pluck('hub_notice_id'))->values();
        $context = app(HubProjectContext::class)->forUser($run->user_id ? User::find($run->user_id) : null);

        return [
            'query' => $run->query, 'target_notice_id' => $run->hub_notice_id,
            'captured_at' => now()->toIso8601String(),
            'notice_versions' => $notices->pluck('revision', 'id')->all(),
            ...$context,
            'projects' => $this->relevantProjects($context['projects'], $notices->first()?->title ?? $run->query),
            'evidence_ids' => $evidence->pluck('id')->all(),
            'allowed_evidence_ids' => $evidence->pluck('id')->all(),
            'source_ids' => HubSource::where('enabled', true)->where(fn ($query) => $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subHours(12)))->pluck('id')->all(),
            'notices' => $notices->toArray(),
            'evidence' => $evidence->map(fn ($e) => ['id' => $e->id, 'notice_id' => $e->hub_notice_id, 'url' => $e->url, 'content_hash' => $e->content_hash, 'text' => mb_substr($e->text, 0, 6000), 'extraction' => $e->extraction])->all(),
            'statistics' => $this->rules->prices($notices),
            'sample_count' => $notices->count(),
            'limitations' => ['仅反映已取得并可核验的公开样本，不代表全市场。', '本次最多使用 10 份公告证据，每份正文前 6000 字、最多 20 条相关当前账号可见项目的必要字段；附件及更长正文可能未纳入结论。', '未确认集团归属不用于认定同集团合作。', '项目主档记录不等同于投标或中标；不推断集团合作史或胜率。', ...(empty($context['projects']) ? ['暂无当前账号可参考的项目主档，本次仅分析公开信息。'] : [])],
        ];
    }

    /** Keep related records and explicit lost-bid evidence within the input budget. */
    private function relevantProjects(array $projects, string $target): array
    {
        $terms = array_values(array_filter(config('procurement_hub.keywords'), fn ($term) => app(HubScreening::class)->matches($target, $term)));

        $relevance = function ($project) use ($terms): int {
            $text = implode(' ', array_filter($project, 'is_scalar'));
            $matches = count(array_filter($terms, fn ($term) => str_contains($text, $term)));

            return $matches * 10 + (preg_match('/未中标|未中|流标|价格太低/u', (string) ($project['remark'] ?? '')) ? 50 : 0);
        };

        return collect($projects)->filter(fn ($project) => $relevance($project) > 0)->sortByDesc($relevance)->take(config('procurement_hub.project_limit'))->values()->all();
    }
}
