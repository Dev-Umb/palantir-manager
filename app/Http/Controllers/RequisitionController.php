<?php

namespace App\Http\Controllers;

use App\Actions\CreateObjectRecord;
use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Support\ObjectRelations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RequisitionController extends Controller
{
    public function __construct(private ObjectRelations $relations) {}

    public function create(Request $request): Response
    {
        return Inertia::render('Requisitions/Create', [
            'materials' => $this->relations->optionsForObjectKey('material'),
            'projects' => $this->relations->optionsForObjectKey('project', $request->user()),
            'materialSearchUrl' => route('relation-options.index', [
                'source_object' => 'requisition',
                'field' => 'material_id',
            ], false),
            'projectSearchUrl' => route('relation-options.index', [
                'source_object' => 'requisition',
                'field' => 'project_id',
            ], false),
            'submitUrl' => route('requisitions.store'),
            'publicForm' => false,
        ]);
    }

    public function publicCreate(): Response
    {
        return Inertia::render('Requisitions/Create', [
            'materials' => $this->relations->optionsForObjectKey('material'),
            'projects' => [],
            'materialSearchUrl' => route('requisitions.public.material-options', absolute: false),
            'projectSearchUrl' => '',
            'submitUrl' => route('requisitions.public.store'),
            'publicForm' => true,
        ]);
    }

    public function publicMaterialOptions(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) ($validated['q'] ?? ''));
        $material = BusinessObject::where('key', 'material')->firstOrFail();
        $query = $material->records()
            ->where(function ($query): void {
                $query->whereNull('payload->status')
                    ->orWhere('payload->status', '!=', '停用');
            })
            ->latest();
        if ($search !== '') {
            $pattern = "%{$search}%";
            $query->where(function ($query) use ($pattern): void {
                $query->whereLike('code', $pattern, caseSensitive: false)
                    ->orWhereLike('title', $pattern, caseSensitive: false)
                    ->orWhereLike('payload->material_code', $pattern, caseSensitive: false)
                    ->orWhereLike('payload->name', $pattern, caseSensitive: false);
            });
        }

        return response()->json([
            'items' => $query->limit(50)->get(['id', 'code', 'title', 'payload'])
                ->map(fn (ObjectRecord $record) => [
                    'id' => $record->id,
                    'label' => trim($record->code.' · '.$record->title),
                    'code' => $record->code,
                    'title' => $record->title,
                    'meta' => [],
                ])->values(),
        ]);
    }

    public function approvals(): Response
    {
        $object = BusinessObject::where('key', 'requisition')->firstOrFail();
        $query = fn () => $object->records()->with('businessObject')->latest();
        $pending = $query()
            ->where('payload->status', '待处理')
            ->get();
        $processed = $query()
            ->where('payload->status', '!=', '待处理')
            ->take(30)
            ->get();
        $this->relations->preloadLabels($pending->merge($processed));

        return Inertia::render('Requisitions/Approvals', [
            'pending' => $pending
                ->map(fn (ObjectRecord $record) => $this->relations->formatRecord($record))
                ->values(),
            'processed' => $processed
                ->map(fn (ObjectRecord $record) => $this->relations->formatRecord($record))
                ->values(),
        ]);
    }

    public function store(Request $request, CreateObjectRecord $writer): RedirectResponse
    {
        $this->storeRequest($request, $writer);

        return redirect()->route('dashboard')->with('status', '采购申请已提交，采购会先审批。');
    }

    public function publicStore(Request $request, CreateObjectRecord $writer): RedirectResponse
    {
        $this->storeRequest($request, $writer, publicForm: true);

        return redirect()->route('requisitions.public.create')->with('status', '采购申请已提交，请等待采购审批。');
    }

    public function approve(Request $request, ObjectRecord $record, CreateObjectRecord $writer): RedirectResponse
    {
        DB::transaction(function () use ($request, $record, $writer): void {
            $this->relations->lockReferenceGraph();
            $locked = ObjectRecord::with('businessObject')
                ->lockForUpdate()
                ->findOrFail($record->id);
            $object = $locked->businessObject;
            abort_unless($object?->key === 'requisition', 404);

            $payload = $locked->payload ?? [];
            $this->guardPending($payload);
            $this->validateRequisitionPayload($payload);
            $this->relations->validatePayloadRelations($object, $payload, $request->user());

            $purchase = BusinessObject::where('key', 'purchase')->firstOrFail();
            $purchasePayload = [
                'date' => now()->format('Y-m-d'),
                'project_id' => $payload['project_id'] ?? '',
                'requester' => $payload['requester'] ?? '',
                'items' => [[
                    'id' => (string) Str::uuid(),
                    'material_id' => $payload['material_id'],
                    'reported_qty' => trim(($payload['qty'] ?? '').($payload['unit'] ?? '')),
                    'qty' => $payload['qty'],
                    'weight_ton' => null,
                    'unit' => $payload['unit'] ?? '',
                    'arrived' => '未到货',
                    'daily_status' => '未采购',
                ]],
            ];
            $this->relations->validatePayloadRelations($purchase, $purchasePayload, $request->user());
            $this->relations->validateItemRelations($purchase, $purchasePayload, $request->user());
            $writer->handle($purchase, $purchasePayload, $request->user(), 'requisition.approve');

            $payload['status'] = '已转采购';
            $locked->update(['payload' => $payload]);
        });

        return back()->with('status', '采购申请已通过，已生成采购执行记录。');
    }

    public function reject(Request $request, ObjectRecord $record): RedirectResponse
    {
        DB::transaction(function () use ($request, $record): void {
            $locked = ObjectRecord::with('businessObject')
                ->lockForUpdate()
                ->findOrFail($record->id);
            abort_unless($locked->businessObject?->key === 'requisition', 404);

            $payload = $locked->payload ?? [];
            $this->guardPending($payload);
            $oldStatus = $payload['status'];
            $payload['status'] = '已驳回';
            $locked->update(['payload' => $payload]);

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'requisition.reject',
                'subject_type' => 'requisition',
                'subject_id' => $locked->id,
                'payload' => [
                    'old_status' => $oldStatus,
                    'new_status' => $payload['status'],
                    'reason' => $payload['reason'] ?? '',
                    'record' => [
                        'code' => $locked->code,
                        'title' => $locked->title,
                        'requester' => $payload['requester'] ?? '',
                        'material_id' => $payload['material_id'] ?? null,
                        'project_id' => $payload['project_id'] ?? null,
                        'qty' => $payload['qty'] ?? null,
                        'unit' => $payload['unit'] ?? '',
                    ],
                ],
            ]);
        });

        return back()->with('status', '采购申请已驳回。');
    }

    private function storeRequest(Request $request, CreateObjectRecord $writer, bool $publicForm = false): void
    {
        $data = $request->validate([
            'requester' => ['required', 'string'],
            'material_id' => ['required', 'string', 'uuid'],
            'qty' => ['required', 'numeric', 'min:0.01'],
            'unit' => ['nullable', 'string'],
            'project_id' => ['nullable', 'string', 'uuid'],
            'urgency' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'material_id.uuid' => '关联记录格式不正确',
            'project_id.uuid' => '关联记录格式不正确',
        ]);

        if ($publicForm && filled($data['project_id'] ?? null)) {
            throw ValidationException::withMessages([
                'project_id' => '公开采购申请不能关联项目。',
            ]);
        }

        $object = BusinessObject::where('key', 'requisition')->firstOrFail();
        $data['status'] = '待处理';
        DB::transaction(function () use ($object, $data, $request, $writer): void {
            $this->relations->lockReferenceGraph();
            $this->relations->validatePayloadRelations($object, $data, $request->user());
            $writer->handle($object, $data, $request->user(), 'requisition.create');
        });
    }

    private function guardPending(array $payload): void
    {
        if (($payload['status'] ?? null) !== '待处理') {
            throw ValidationException::withMessages([
                'status' => '该采购申请已处理，不能重复操作。',
            ]);
        }
    }

    private function validateRequisitionPayload(array $payload): void
    {
        Validator::make(['payload' => $payload], [
            'payload.requester' => ['required', 'string'],
            'payload.material_id' => ['required', 'string', 'uuid'],
            'payload.qty' => ['required', 'numeric', 'min:0.01'],
            'payload.unit' => ['nullable', 'string'],
            'payload.project_id' => ['nullable', 'string', 'uuid'],
        ], [
            'payload.material_id.uuid' => '关联记录格式不正确',
            'payload.project_id.uuid' => '关联记录格式不正确',
        ])->validate();
    }
}
