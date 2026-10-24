<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProcurementClientRequest;
use App\Http\Requests\ProcurementFilterRequest;
use App\Models\ProcurementBatch;
use App\Models\ProcurementClient;
use App\Models\ProcurementNotice;
use App\Models\ProcurementObservation;
use App\Models\User;
use App\Support\HubAccess;
use App\Support\ProcurementPayload;
use App\Support\ProcurementQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProcurementController extends Controller
{
    public function index(ProcurementFilterRequest $request, ProcurementQuery $query): Response
    {
        return Inertia::render('Procurement/Index', $query->result($request->user(), $request->validated()));
    }

    public function show(Request $request, int $notice, ProcurementQuery $query): Response
    {
        $record = $query->visible($request->user())->findOrFail($notice);

        return Inertia::render('Procurement/Show', ['notice' => $record, 'warnings' => app(ProcurementPayload::class)->warnings($record->payload), 'batch' => ProcurementBatch::findOrFail($record->batch_id)->only(['id', 'created_at'])]);
    }

    public function batches(Request $request): Response
    {
        $q = ProcurementBatch::query()->where('status', '!=', 'validated');
        if (! HubAccess::canManage($request->user())) {
            $q->whereIn('client_id', ProcurementClient::where('user_id', $request->user()->id)->select('id'));
        }

        return Inertia::render('Procurement/Batches', ['batches' => $q->latest()->paginate(20)->through(fn (ProcurementBatch $b) => ['id' => $b->id, 'status' => $b->status, 'date' => $b->payload['date'], 'title' => $b->payload['title'], 'created_at' => $b->created_at]), 'canManage' => HubAccess::canManage($request->user())]);
    }

    public function batch(Request $request, string $batch): Response
    {
        $b = ProcurementBatch::findOrFail($batch);
        $client = ProcurementClient::findOrFail($b->client_id);
        abort_unless(HubAccess::canManage($request->user()) || $client->user_id === $request->user()->id, 404);
        $observations = ProcurementObservation::where('batch_id', $b->id)->get()->each(function (ProcurementObservation $observation): void {
            if ($observation->status === 'identity_review' && ! HubAccess::canManage(request()->user())) {
                $observation->makeHidden('notice_id');
            }
        });

        return Inertia::render('Procurement/Batch', ['batch' => $b, 'observations' => $observations, 'canManage' => HubAccess::canManage($request->user())]);
    }

    public function clients(Request $request): Response
    {
        abort_unless(HubAccess::canManage($request->user()), 403);

        return Inertia::render('Procurement/Clients', ['clients' => ProcurementClient::latest()->get(), 'users' => User::whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'tender', 'business']))->get(['id', 'name']), 'newToken' => $request->session()->pull('procurement_new_token')]);
    }

    public function createClient(ProcurementClientRequest $request): RedirectResponse
    {
        $data = $request->validated();
        abort_unless(HubAccess::canRead(User::find($data['user_id'])), 422);
        $token = Str::random(64);
        ProcurementClient::create(['user_id' => $data['user_id'], 'name' => $data['name'], 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays($data['days'])]);

        return redirect()->route('hub.clients')->with('procurement_new_token', $token);
    }

    public function revoke(Request $request, ProcurementClient $client): RedirectResponse
    {
        abort_unless(HubAccess::canManage($request->user()), 403);
        $client->update(['revoked_at' => now()]);

        return back();
    }

    public function accept(Request $request, ProcurementObservation $observation, ProcurementPayload $adapter): RedirectResponse
    {
        abort_unless(HubAccess::canManage($request->user()), 403);
        DB::transaction(function () use ($observation, $adapter): void {
            $observation = ProcurementObservation::whereKey($observation->id)->lockForUpdate()->firstOrFail();
            abort_unless($observation->status === 'pending_update', 409);
            $n = ProcurementNotice::whereKey($observation->notice_id)->lockForUpdate()->firstOrFail();
            $i = $observation->payload;
            $d = $adapter->dates($i);
            $n->update(['payload' => $i, 'batch_id' => $observation->batch_id, 'title' => $i['title'], 'method' => $i['nature'], 'priority' => $i['priority'], 'status' => $i['status'], 'invited' => $n->invited || $i['invited'], 'has_issues' => $i['issues'] !== '', 'published_date' => $d['published']['date'], 'registration_at' => $d['registration']['value'], 'deadline_at' => $d['deadline']['value'], 'dates' => $d, 'content_hash' => $adapter->contentHash($i)]);
            $observation->update(['status' => 'accepted']);
        });

        return back();
    }
}
