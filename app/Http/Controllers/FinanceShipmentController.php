<?php

namespace App\Http\Controllers;

use App\Actions\SaveFinanceShipments;
use App\Http\Requests\SaveFinanceShipmentRequest;
use App\Models\AuditLog;
use App\Models\FinanceShipmentLedger;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\FinanceShipmentAccess;
use App\Support\FinanceShipmentImport;
use App\Support\ProjectVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FinanceShipmentController extends Controller
{
    public function __construct(private FinanceShipmentAccess $access, private SaveFinanceShipments $writer, private ProjectVisibility $visibility) {}

    public function index(Request $request, FinanceShipmentImport $imports): Response
    {
        $this->access->authorize($request->user());
        $projects = $this->visibility->scope(ObjectRecord::whereRelation('businessObject', 'key', 'project'), $request->user())->orderBy('title')->get();
        $projects = $projects->keyBy('id');
        $writableProjects = $projects->filter(fn (ObjectRecord $project): bool => $this->visibility->allowsRecordWrite($request->user(), $project));
        $ledgers = FinanceShipmentLedger::where(function ($query) use ($projects, $request): void {
            $query->whereIn('project_id', $projects->modelKeys());
            if ($this->access->allowsUnlinked($request->user())) {
                $query->orWhereNull('project_id');
            }
        })->orderBy('id')->get();
        $pendingImports = DB::table('finance_shipment_imports')->whereIn('ledger_id', $ledgers->modelKeys())->get()
            ->groupBy('ledger_id')->map(fn ($items): int => $items->sum(fn ($item): int => $imports->present($item)['pending_outside_ledger']));
        $owners = User::whereIn('id', $projects->pluck('payload.business_owner_user_id')->filter())->get()->keyBy('id');
        $customers = ObjectRecord::whereIn('id', $projects->pluck('payload.customer_id')->filter())->get()->keyBy('id');
        $rows = $ledgers->map(function (FinanceShipmentLedger $ledger) use ($projects, $owners, $pendingImports, $writableProjects): array {
            $project = $projects->get($ledger->project_id);
            $owner = $project?->payload['business_owner_user_id'] ?? null;
            $summary = $this->writer->summary($ledger->orders);
            $canEdit = ! $project || $writableProjects->has($project->id);

            return ['id' => 'ledger-'.$ledger->id, 'ledger_id' => $ledger->id, 'revision' => $ledger->revision,
                'project_id' => $ledger->project_id, 'title' => $ledger->display_title ?: $project?->title ?: $ledger->title ?: '未命名台账',
                'source_title' => $ledger->title, 'project_title' => $project?->title, 'code' => $project?->code,
                'owner_id' => $project ? ($owner ?: 'unassigned') : 'unlinked',
                'owner' => $project ? ($owners->get($owner)?->name ?? '未分配业务员') : '待关联资料',
                'tonnes' => $summary['tonnes'], 'last_date' => $summary['last_date'],
                'pending' => $summary['pending'] + ($pendingImports->get($ledger->id) ?? 0),
                'order_count' => $this->writer->documentCount($ledger->orders),
                'url' => route('finance-shipments.ledgers.show', $ledger),
                'rename_url' => $canEdit ? route('finance-shipments.ledgers.rename', $ledger) : null,
                'association_url' => $canEdit ? route('finance-shipments.ledgers.associate', $ledger) : null];
        });
        foreach ($projects->except($ledgers->pluck('project_id')->filter()->all()) as $project) {
            $owner = $project->payload['business_owner_user_id'] ?? null;
            $rows->push(['id' => $project->id, 'title' => $project->title, 'code' => $project->code,
                'owner_id' => $owner ?: 'unassigned', 'owner' => $owners->get($owner)?->name ?? '未分配业务员',
                'tonnes' => null, 'last_date' => null, 'pending' => 0, 'order_count' => 0,
                'revision' => 0, 'rename_url' => $writableProjects->has($project->id) ? route('finance-shipments.rename', $project) : null,
                'url' => route('finance-shipments.show', $project)]);
        }

        return Inertia::render('FinanceShipments/Index', [
            'projects' => $rows->sortBy('title')->values(),
            'projectOptions' => $writableProjects->map(fn (ObjectRecord $project): array => ['value' => $project->id,
                'label' => implode(' · ', array_filter([$project->title, $customers->get($project->payload['customer_id'] ?? '')?->title,
                    $project->code, $owners->get($project->payload['business_owner_user_id'] ?? '')?->name]))])->values(),
            'canUnlink' => $this->access->allowsUnlinked($request->user()),
            'indexUrl' => route('finance-shipments.index'),
        ]);
    }

    public function show(Request $request, ObjectRecord $project, FinanceShipmentImport $imports): Response
    {
        $this->access->authorize($request->user(), $project);
        $ledger = FinanceShipmentLedger::where('project_id', $project->id)->oldest('id')->first();
        if ($ledger) {
            return $this->showLedger($request, $ledger, $imports);
        }
        $owner = User::find($project->payload['business_owner_user_id'] ?? null);

        return Inertia::render('FinanceShipments/Show', ['project' => ['id' => $project->id, 'title' => $project->title, 'code' => $project->code,
            'owner' => $owner?->name ?? '未分配业务员', 'owner_id' => $owner?->id ?? 'unassigned', 'local_source' => $project->payload['finance_local_source'] ?? null],
            'ledger' => $this->writer->current($project),
            'imports' => [],
            'urls' => ['index' => route('finance-shipments.index'), 'save' => route('finance-shipments.save', $project), 'preview' => route('finance-shipments.preview', $project),
                'commit' => route('finance-shipments.commit', $project), 'business' => route('objects.index', ['object' => 'project'])]]);
    }

    public function showLedger(Request $request, FinanceShipmentLedger $ledger, FinanceShipmentImport $imports): Response
    {
        $this->access->authorize($request->user(), $ledger);
        $project = $ledger->project;
        $owner = $project ? User::find($project->payload['business_owner_user_id'] ?? null) : null;

        return Inertia::render('FinanceShipments/Show', [
            'project' => ['id' => $project?->id, 'title' => $ledger->display_title ?: $project?->title ?: $ledger->title ?: '未关联台账',
                'code' => $project?->code, 'owner_id' => $project ? ($owner?->id ?? 'unassigned') : 'unlinked',
                'owner' => $owner?->name ?? '待关联资料'],
            'ledger' => $this->writer->current($ledger),
            'imports' => DB::table('finance_shipment_imports')->where('ledger_id', $ledger->id)->latest()->get()->map($imports->present(...))->values(),
            'urls' => ['index' => route('finance-shipments.index'), 'save' => route('finance-shipments.ledgers.save', $ledger),
                'preview' => route('finance-shipments.ledgers.preview', $ledger), 'commit' => route('finance-shipments.ledgers.commit', $ledger)],
        ]);
    }

    public function renameLedger(Request $request, FinanceShipmentLedger $ledger): JsonResponse
    {
        return $this->rename($request, $ledger);
    }

    public function renameProject(Request $request, ObjectRecord $project): JsonResponse
    {
        return $this->rename($request, $project);
    }

    private function rename(Request $request, ObjectRecord|FinanceShipmentLedger $target): JsonResponse
    {
        $this->access->authorize($request->user(), $target);
        $input = $request->validate(['display_title' => ['required', 'string', 'max:255'], 'revision' => ['required', 'integer', 'min:0']]);

        return DB::transaction(function () use ($request, $target, $input): JsonResponse {
            $ledger = $this->writer->resolve($target, $request->user());
            $ledger = FinanceShipmentLedger::lockForUpdate()->findOrFail($ledger->id);
            $this->access->authorize($request->user(), $ledger);
            abort_unless($ledger->revision === (int) $input['revision'], 409, '台账已更新，名称未覆盖，请刷新后重试。');
            if ($ledger->display_title !== $input['display_title']) {
                $before = $ledger->display_title;
                $ledger->update(['display_title' => $input['display_title'], 'revision' => $ledger->revision + 1]);
                AuditLog::create(['user_id' => $request->user()->id, 'action' => 'finance.shipments.rename',
                    'subject_type' => 'finance_shipment_ledger', 'subject_id' => $ledger->id,
                    'payload' => ['before' => $before, 'after' => $ledger->display_title, 'revision' => $ledger->revision]]);
            }

            return response()->json(['display_title' => $ledger->display_title, 'revision' => $ledger->revision]);
        });
    }

    public function saveLedger(SaveFinanceShipmentRequest $request, FinanceShipmentLedger $ledger): JsonResponse
    {
        return response()->json($this->writer->save($ledger, $request->validated(), $request->user()));
    }

    public function previewLedger(Request $request, FinanceShipmentLedger $ledger, FinanceShipmentImport $imports): JsonResponse
    {
        $this->access->authorize($request->user(), $ledger);
        $request->validate(['file' => ['required', 'file', 'max:20480', 'extensions:xls,xlsx,csv', 'mimes:xls,xlsx,csv,txt']]);
        $file = $request->file('file');

        return response()->json($imports->preview($ledger, $file->getRealPath(), $file->getClientOriginalName(), $request->user()));
    }

    public function commitLedger(Request $request, FinanceShipmentLedger $ledger, FinanceShipmentImport $imports): JsonResponse
    {
        $this->access->authorize($request->user(), $ledger);
        $input = $request->validate(['id' => ['required', 'uuid']]);

        return response()->json($imports->commit($ledger, $input['id'], $request->user()));
    }

    public function associate(Request $request, FinanceShipmentLedger $ledger): JsonResponse
    {
        $this->access->authorize($request->user(), $ledger);
        $input = $request->validate(['project_id' => ['present', 'nullable', 'uuid', 'exists:object_records,id'], 'revision' => ['required', 'integer', 'min:0']]);

        return DB::transaction(function () use ($request, $ledger, $input): JsonResponse {
            $ledger = FinanceShipmentLedger::lockForUpdate()->findOrFail($ledger->id);
            $this->access->authorize($request->user(), $ledger);
            $target = $input['project_id'] ? ObjectRecord::findOrFail($input['project_id']) : null;
            if ($target) {
                $this->access->authorize($request->user(), $target);
            } else {
                abort_unless($this->access->allowsUnlinked($request->user()), 403);
            }
            abort_unless($ledger->revision === (int) $input['revision'], 409, '台账已更新，请刷新后重新关联。');
            $before = $ledger->project_id;
            if ($before !== $target?->id) {
                $ledger->update(['project_id' => $target?->id, 'revision' => $ledger->revision + 1,
                    'title' => $ledger->title ?: $ledger->project?->title ?: '未关联台账']);
                AuditLog::create(['user_id' => $request->user()->id, 'action' => 'finance.shipments.associate',
                    'subject_type' => 'finance_shipment_ledger', 'subject_id' => $ledger->id,
                    'payload' => ['before' => $before, 'after' => $target?->id, 'revision' => $ledger->revision]]);
            }

            return response()->json(['revision' => $ledger->revision, 'project_id' => $ledger->project_id]);
        });
    }

    public function save(SaveFinanceShipmentRequest $request, ObjectRecord $project): JsonResponse
    {
        return response()->json($this->writer->save($project, $request->validated(), $request->user()));
    }

    public function preview(Request $request, ObjectRecord $project, FinanceShipmentImport $imports): JsonResponse
    {
        $this->access->authorize($request->user(), $project);
        $request->validate(['file' => ['required', 'file', 'max:20480', 'extensions:xls,xlsx,csv', 'mimes:xls,xlsx,csv,txt']]);
        $file = $request->file('file');

        return response()->json($imports->preview($project, $file->getRealPath(), $file->getClientOriginalName(), $request->user()));
    }

    public function commit(Request $request, ObjectRecord $project, FinanceShipmentImport $imports): JsonResponse
    {
        $this->access->authorize($request->user(), $project);
        $input = $request->validate(['id' => ['required', 'uuid']]);

        return response()->json($imports->commit($project, $input['id'], $request->user()));
    }
}
