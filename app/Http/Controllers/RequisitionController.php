<?php

namespace App\Http\Controllers;

use App\Actions\CreateObjectRecord;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Support\ObjectRelations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RequisitionController extends Controller
{
    public function __construct(private ObjectRelations $relations)
    {
    }

    public function create(): Response
    {
        return Inertia::render('Requisitions/Create', [
            'materials' => $this->relations->optionsForObjectKey('material'),
            'projects' => $this->relations->optionsForObjectKey('project'),
            'submitUrl' => route('requisitions.store'),
            'publicForm' => false,
        ]);
    }

    public function publicCreate(): Response
    {
        return Inertia::render('Requisitions/Create', [
            'materials' => $this->relations->optionsForObjectKey('material'),
            'projects' => $this->relations->optionsForObjectKey('project'),
            'submitUrl' => route('requisitions.public.store'),
            'publicForm' => true,
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
        $this->storeRequest($request, $writer);

        return redirect()->route('requisitions.public.create')->with('status', '采购申请已提交，请等待采购审批。');
    }

    public function approve(Request $request, ObjectRecord $record, CreateObjectRecord $writer): RedirectResponse
    {
        $object = $record->businessObject;
        abort_unless($object?->key === 'requisition', 404);

        $payload = $record->payload ?? [];
        if (($payload['status'] ?? null) !== '已转采购') {
            $purchase = BusinessObject::where('key', 'purchase')->firstOrFail();
            $writer->handle($purchase, [
                'date' => now()->format('Y-m-d'),
                'project_id' => $payload['project_id'] ?? '',
                'requester' => $payload['requester'] ?? '',
                'material_id' => $payload['material_id'] ?? '',
                'reported_qty' => trim(($payload['qty'] ?? '').($payload['unit'] ?? '')),
                'qty' => $payload['qty'] ?? '',
                'arrived' => '未到货',
                'daily_status' => '未采购',
            ], $request->user(), 'requisition.approve');

            $payload['status'] = '已转采购';
            $record->update(['payload' => $payload]);
        }

        return back()->with('status', '采购申请已通过，已生成采购日报。');
    }

    public function reject(ObjectRecord $record): RedirectResponse
    {
        $object = $record->businessObject;
        abort_unless($object?->key === 'requisition', 404);

        $payload = $record->payload ?? [];
        $payload['status'] = '已驳回';
        $record->update(['payload' => $payload]);

        return back()->with('status', '采购申请已驳回。');
    }

    private function storeRequest(Request $request, CreateObjectRecord $writer): void
    {
        $data = $request->validate([
            'requester' => ['required', 'string'],
            'material_id' => ['required', 'string'],
            'qty' => ['required', 'numeric', 'min:0.01'],
            'unit' => ['nullable', 'string'],
            'project_id' => ['nullable', 'string'],
            'urgency' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $object = BusinessObject::where('key', 'requisition')->firstOrFail();
        $this->relations->validatePayloadRelations($object, $data);

        $data['status'] = '待处理';
        $writer->handle($object, $data, $request->user(), 'requisition.create');
    }
}
