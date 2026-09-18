<?php

namespace App\Http\Controllers;

use App\Actions\BuildHubResearch;
use App\Actions\PrepareHubFeed;
use App\Http\Requests\HubResearchRequest;
use App\Http\Requests\HubSubscriptionRequest;
use App\Jobs\RunHubStage;
use App\Models\HubBookmark;
use App\Models\HubEvidence;
use App\Models\HubNotice;
use App\Models\HubRun;
use App\Models\HubSource;
use App\Models\HubSubscription;
use App\Support\HubAccess;
use App\Support\HubProjectContext;
use App\Support\HubScreening;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class HubController extends Controller
{
    public function index(Request $request): Response
    {
        $context = app(HubProjectContext::class)->forUser($request->user());
        app(PrepareHubFeed::class)->sources();
        $query = $this->filtered($request->only('q', 'region', 'kind'));
        if (! $request->boolean('history')) {
            $query->where(fn (Builder $q) => $q->whereNull('facts->registration_closed')->orWhere('facts->registration_closed', false)->orWhereIn('kind', ['award', 'candidate', 'termination']));
            $query->where(fn (Builder $q) => $q->whereNull('deadline')->orWhere('deadline', '>=', now())->orWhereIn('kind', ['award', 'candidate', 'termination']));
        }
        $scoreColumn = DB::connection()->getQueryGrammar()->wrap('score->value');
        $latestScore = HubRun::selectRaw('CAST('.$scoreColumn.' AS INTEGER)')->whereColumn('hub_notice_id', 'hub_notices.id')->where('user_id', $request->user()->id)->where('snapshot->project_version', $context['project_version'])->where('status', 'published')->whereNotNull('published_at')->whereNull('stale_at')->whereNotNull('score')->latest('id')->limit(1);
        $query->addSelect(['recommendation_score' => $latestScore]);
        $query->addSelect(['registration_text' => HubEvidence::select('text')->whereColumn('hub_notice_id', 'hub_notices.id')->latest('id')->limit(1)]);
        if ($request->input('sort', 'recommended') === 'recommended') {
            $query->orderByRaw("CASE WHEN kind IN ('notice', 'intent', 'amendment') AND (deadline IS NULL OR deadline >= ?) THEN 0 ELSE 1 END", [now()]);
            $query->orderByRaw('COALESCE(('.$latestScore->toSql().'), -1) DESC', $latestScore->getBindings());
            $terms = app(HubScreening::class)->terms($context['projects']);
            if ($terms) {
                $query->orderByRaw(implode(' + ', array_fill(0, count($terms), '(CASE WHEN title LIKE ? THEN 1 ELSE 0 END)')).' DESC', array_map(fn ($term) => '%'.$term.'%', $terms));
            }
        }
        $notices = $query->with(['evidence' => fn ($q) => $q->select('id', 'hub_notice_id', 'url', 'hub_source_id')])->latest('published_at')->latest('id')->paginate(20)->withQueryString();
        app(PrepareHubFeed::class)->research($request->user(), $notices->getCollection(), $context);
        $briefings = $notices->getCollection()->take(3)->map(fn ($notice) => app(HubScreening::class)->brief($notice, $context))->values();
        $summaries = HubRun::whereIn('hub_notice_id', $notices->pluck('id'))->where('user_id', $request->user()->id)->where('snapshot->project_version', $context['project_version'])->where('status', 'published')->whereNotNull('published_at')->whereNull('stale_at')->latest('id')->get()->unique('hub_notice_id')->keyBy('hub_notice_id');
        $notices->through(function ($notice) use ($summaries, $context): array {
            $registration = app(HubScreening::class)->registrationWindow($notice->registration_text ?? '');
            unset($notice['registration_text']);

            return [...$notice->toArray(), 'registration' => $registration, 'recommendation_summary' => collect($summaries->get($notice->id)?->result['recommendation']['claims'] ?? [])->firstWhere('section', 'decision')['text'] ?? null, 'screening' => app(HubScreening::class)->describe($notice, $context)];
        });
        $bookmarks = HubBookmark::where('user_id', $request->user()->id)->pluck('hub_notice_id');

        return Inertia::render('ProcurementHub/Index', [
            'notices' => $notices, 'filters' => $request->only('q', 'region', 'kind', 'sort', 'history'), 'bookmarks' => $bookmarks,
            'automation' => ['projectCount' => count($context['projects']), 'pending' => HubRun::where('user_id', $request->user()->id)->where('kind', 'research')->whereIn('status', ['queued', 'running'])->count(), 'sourceCount' => HubSource::where('enabled', true)->count(), 'lastUpdated' => HubEvidence::latest('fetched_at')->first()?->fetched_at?->toIso8601String()],
            'focusReport' => app(HubScreening::class)->overview($context), 'briefings' => $briefings,
            'hubLinks' => $this->links(), 'canManage' => HubAccess::canManage($request->user()),
        ]);
    }

    public function show(Request $request, HubNotice $notice): Response
    {
        $notice->load('evidence');
        $context = app(HubProjectContext::class)->forUser($request->user());
        app(PrepareHubFeed::class)->research($request->user(), collect([$notice]), $context);
        $reports = $notice->reports()->where(fn ($q) => $q->where('user_id', $request->user()->id)->orWhereNull('user_id'))->whereNotNull('published_at')->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$request->user()->id])->latest('id')->limit(5)->get()->map(fn ($r) => $this->publicRun($r));
        $publishedId = $notice->reports()->where('user_id', $request->user()->id)->where('status', 'published')->whereNotNull('published_at')->where('snapshot->project_version', $context['project_version'])->whereNull('stale_at')->max('id') ?? 0;
        $pending = $notice->reports()->where('user_id', $request->user()->id)->where('id', '>', $publishedId)->whereIn('status', ['queued', 'running', 'failed', 'insufficient', 'stale'])->latest('id')->first();
        HubBookmark::where('user_id', $request->user()->id)->where('hub_notice_id', $notice->id)->update(['seen_at' => now()]);

        return Inertia::render('ProcurementHub/Show', [
            'notice' => $notice, 'briefing' => app(HubScreening::class)->brief($notice, $context), 'screening' => app(HubScreening::class)->describe($notice, $context), 'reports' => $reports, 'pendingRun' => $pending ? $this->publicRun($pending) : null,
            'timeline' => HubNotice::where('project_key', $notice->project_key)->orderBy('published_at')->get(['id', 'title', 'kind', 'published_at']),
            'bookmarked' => HubBookmark::where('user_id', $request->user()->id)->where('hub_notice_id', $notice->id)->exists(),
            'hubLinks' => $this->links(),
        ]);
    }

    public function research(Request $request): Response
    {
        return Inertia::render('ProcurementHub/Research', ['runs' => HubRun::where('kind', 'research')->where('user_id', $request->user()->id)->latest('id')->paginate(20)->through(fn ($r) => $this->publicRun($r)), 'hubLinks' => $this->links()]);
    }

    public function storeResearch(HubResearchRequest $request, BuildHubResearch $research): RedirectResponse
    {
        $data = $request->validated();
        $notice = isset($data['notice_id']) ? HubNotice::findOrFail($data['notice_id']) : null;
        $research->start($request->user()->id, $data['query'] ?? $notice?->title ?? '', $notice?->id);

        return to_route('hub.research')->with('status', '研究已排队，可继续浏览公告。');
    }

    public function bookmark(Request $request, HubNotice $notice): RedirectResponse
    {
        $bookmark = HubBookmark::firstOrNew(['user_id' => $request->user()->id, 'hub_notice_id' => $notice->id]);
        if ($bookmark->exists) {
            $bookmark->delete();
        } else {
            $bookmark->seen_at = now();
            $bookmark->save();
        }

        return back();
    }

    public function follows(Request $request): Response
    {
        $bookmarks = HubBookmark::where('user_id', $request->user()->id)->get()->keyBy('hub_notice_id');
        $notices = HubNotice::whereIn('id', $bookmarks->keys())->latest('updated_at')->get()->map(fn ($n) => [...$n->toArray(), 'has_update' => ! $bookmarks[$n->id]->seen_at || $n->updated_at->gt($bookmarks[$n->id]->seen_at)]);
        $subscriptions = HubSubscription::where('user_id', $request->user()->id)->latest('id')->get()->map(function ($s) {
            $matches = $this->filtered($s->filters)->latest('updated_at')->limit(20)->get();

            return [...$s->toArray(), 'matches' => $matches->map(fn ($n) => [...$n->toArray(), 'has_update' => ! $s->seen_at || $n->updated_at->gt($s->seen_at)])];
        });

        return Inertia::render('ProcurementHub/Follows', ['notices' => $notices, 'subscriptions' => $subscriptions, 'hubLinks' => $this->links()]);
    }

    public function subscribe(HubSubscriptionRequest $request): RedirectResponse
    {
        HubSubscription::create([...$request->validated(), 'user_id' => $request->user()->id, 'seen_at' => now()]);

        return back()->with('status', '订阅已保存。');
    }

    public function deleteSubscription(Request $request, HubSubscription $subscription): RedirectResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 403);
        $subscription->delete();

        return back();
    }

    public function readSubscription(Request $request, HubSubscription $subscription): RedirectResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 403);
        $subscription->update(['seen_at' => now()]);

        return back();
    }

    public function cancel(Request $request, HubRun $run): RedirectResponse
    {
        abort_unless($run->user_id === $request->user()->id || HubAccess::canManage($request->user()), 403);
        HubRun::whereKey($run->id)->whereIn('status', ['queued', 'running'])->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return back();
    }

    public function retry(Request $request, HubRun $run): RedirectResponse
    {
        abort_unless($run->user_id === $request->user()->id || HubAccess::canManage($request->user()), 403);
        if (HubRun::whereKey($run->id)->where('status', 'failed')->whereNull('cancelled_at')->update(['status' => 'queued', 'error' => null])) {
            RunHubStage::dispatch($run->id, $run->stage, $run->round);
        }

        return back();
    }

    private function filtered(array $filters): Builder
    {
        return HubNotice::query()->select('hub_notices.*')
            ->when($filters['q'] ?? '', function (Builder $q, string $term): void {
                $term = '%'.mb_substr($term, 0, 300).'%';
                $q->where(fn (Builder $q) => $q->where('title', 'like', $term)->orWhere('buyer', 'like', $term)->orWhere('product', 'like', $term)->orWhere('group_name', 'like', $term));
            })->when($filters['region'] ?? '', fn ($q, $region) => $q->where('region', $region))
            ->when($filters['kind'] ?? '', fn ($q, $kind) => $q->where('kind', $kind));
    }

    private function publicRun(HubRun $run): array
    {
        $contextAllowed = $run->user_id === null
            ? empty($run->snapshot['projects']) && empty($run->snapshot['confirmed_companies'])
            : $run->user_id === auth()->id() && (! $run->published_at || app(HubProjectContext::class)->current($run->snapshot ?? []));
        if (! $contextAllowed) {
            return [...$run->only(['id', 'hub_notice_id', 'status', 'stage', 'created_at']), 'query' => '项目参考已变化',
                'status' => 'stale', 'stale_at' => now()->toIso8601String(), 'result' => null, 'score' => null, 'evidence' => [], 'audit_issues' => []];
        }

        return [...$run->only(['id', 'hub_notice_id', 'query', 'status', 'stage', 'round', 'published_at', 'stale_at', 'created_at', 'error', 'score']),
            'result' => $run->published_at && $run->status === 'published' ? $run->result : null,
            'audit_issues' => $run->audit['issues'] ?? [],
            'evidence' => $run->published_at ? collect($run->snapshot['evidence'] ?? [])->map(fn ($e) => array_intersect_key($e, array_flip(['id', 'notice_id', 'url'])))->values()->all() : [],
        ];
    }

    public function links(): array
    {
        return ['index' => route('hub.index'), 'research' => route('hub.research'), 'follows' => route('hub.follows'), 'company' => route('hub.company'), 'admin' => route('hub.admin')];
    }
}
