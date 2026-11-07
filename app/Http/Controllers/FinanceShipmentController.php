<?php

namespace App\Http\Controllers;

use App\Actions\SaveFinanceShipments;
use App\Http\Requests\SaveFinanceShipmentRequest;
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
        $counts = DB::table('finance_shipment_ledgers')->whereIn('project_id', $projects->modelKeys())->get()->keyBy('project_id');
        $pendingImports = DB::table('finance_shipment_imports')->whereIn('project_id', $projects->modelKeys())->get()
            ->groupBy('project_id')->map(fn ($items): int => $items->sum(fn ($item): int => $imports->present($item)['pending_outside_ledger']));
        $owners = User::whereIn('id', $projects->pluck('payload.business_owner_user_id')->filter())->get()->keyBy('id');

        return Inertia::render('FinanceShipments/Index', ['projects' => $projects->map(function (ObjectRecord $project) use ($owners, $counts, $pendingImports): array {
            $owner = $project->payload['business_owner_user_id'] ?? null;
            $ledger = $counts->get($project->id);
            $summary = $ledger ? $this->writer->summary(json_decode($ledger->orders, true)) : null;

            return ['id' => $project->id, 'title' => $project->title, 'code' => $project->code, 'owner_id' => $owner ?: 'unassigned', 'owner' => $owners->get($owner)?->name ?? '未分配业务员',
                'tonnes' => $summary['tonnes'] ?? null, 'last_date' => $summary['last_date'] ?? null, 'pending' => ($summary['pending'] ?? 0) + ($pendingImports->get($project->id) ?? 0),
                'order_count' => $ledger ? $this->writer->documentCount(json_decode($ledger->orders, true)) : 0, 'url' => route('finance-shipments.show', $project)];
        })->values(), 'indexUrl' => route('finance-shipments.index')]);
    }

    public function show(Request $request, ObjectRecord $project, FinanceShipmentImport $imports): Response
    {
        $this->access->authorize($request->user(), $project);
        $owner = User::find($project->payload['business_owner_user_id'] ?? null);

        return Inertia::render('FinanceShipments/Show', ['project' => ['id' => $project->id, 'title' => $project->title, 'code' => $project->code,
            'owner' => $owner?->name ?? '未分配业务员', 'owner_id' => $owner?->id ?? 'unassigned', 'local_source' => $project->payload['finance_local_source'] ?? null],
            'ledger' => $this->writer->current($project),
            'imports' => DB::table('finance_shipment_imports')->where('project_id', $project->id)->latest()->get()->map($imports->present(...))->values(),
            'urls' => ['index' => route('finance-shipments.index'), 'save' => route('finance-shipments.save', $project), 'preview' => route('finance-shipments.preview', $project),
                'commit' => route('finance-shipments.commit', $project), 'business' => route('objects.index', ['object' => 'project'])]]);
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
