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

class ShopFloorController extends Controller
{
    public function __construct(private ObjectRelations $relations)
    {
    }

    public function materialRequestCreate(): Response
    {
        return Inertia::render('MaterialRequests/Create', [
            'materials' => $this->relations->optionsForObjectKey('material'),
            'projects' => $this->relations->optionsForObjectKey('project'),
            'submitUrl' => route('material-requests.public.store'),
        ]);
    }

    public function materialRequestStore(Request $request, CreateObjectRecord $writer): RedirectResponse
    {
        $data = $request->validate([
            'requester' => ['required', 'string', 'max:120'],
            'material_id' => ['required', 'string'],
            'project_id' => ['nullable', 'string'],
            'qty' => ['required', 'numeric', 'min:0.01'],
            'unit' => ['nullable', 'string'],
            'team' => ['nullable', 'string'],
            'apply_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $object = BusinessObject::where('key', 'material_request')->firstOrFail();
        $this->relations->validatePayloadRelations($object, $data);

        $data['status'] = '待审批';
        $writer->handle($object, $data, null, 'material_request.create');

        return redirect()->route('material-requests.public.create')->with('status', '领料申请已提交，请等待库管审批。');
    }

    public function materialRequestApprovals(): Response
    {
        $object = BusinessObject::where('key', 'material_request')->firstOrFail();
        $query = fn () => $object->records()->with('businessObject')->latest();
        $pending = $query()->where('payload->status', '待审批')->get();
        $processed = $query()->where('payload->status', '!=', '待审批')->take(30)->get();
        $this->relations->preloadLabels($pending->merge($processed));

        return Inertia::render('MaterialRequests/Approvals', [
            'pending' => $pending->map(fn (ObjectRecord $record) => $this->relations->formatRecord($record))->values(),
            'processed' => $processed->map(fn (ObjectRecord $record) => $this->relations->formatRecord($record))->values(),
        ]);
    }

    public function materialRequestApprove(Request $request, ObjectRecord $record, CreateObjectRecord $writer): RedirectResponse
    {
        abort_unless($record->businessObject?->key === 'material_request', 404);

        $payload = $record->payload ?? [];
        if (($payload['status'] ?? null) !== '已出库') {
            $outbound = BusinessObject::where('key', 'outbound')->firstOrFail();
            $outboundRecord = $writer->handle($outbound, [
                'material_id' => $payload['material_id'] ?? '',
                'project_id' => $payload['project_id'] ?? '',
                'qty' => $payload['qty'] ?? '',
                'team' => $payload['team'] ?? '',
                'apply_date' => $payload['apply_date'] ?? now()->format('Y-m-d'),
            ], $request->user(), 'material_request.approve');

            $payload['status'] = '已出库';
            $payload['outbound_id'] = $outboundRecord->id;
            $record->update(['payload' => $payload]);
        }

        return back()->with('status', '领料申请已通过，已生成出库单。');
    }

    public function materialRequestReject(ObjectRecord $record): RedirectResponse
    {
        abort_unless($record->businessObject?->key === 'material_request', 404);

        $payload = $record->payload ?? [];
        $payload['status'] = '已驳回';
        $record->update(['payload' => $payload]);

        return back()->with('status', '领料申请已驳回。');
    }

    public function teamLogCreate(): Response
    {
        return Inertia::render('TeamLogs/Create', [
            'workOrders' => $this->relations->optionsForObjectKey('work_order'),
            'submitUrl' => route('team-logs.public.store'),
        ]);
    }

    public function teamLogStore(Request $request, CreateObjectRecord $writer): RedirectResponse
    {
        $data = $request->validate([
            'work_order_id' => ['required', 'string'],
            'part_name' => ['nullable', 'string', 'max:160'],
            'team' => ['nullable', 'string'],
            'real_qty' => ['required', 'numeric', 'min:0'],
            'work_date' => ['nullable', 'date'],
        ]);

        $object = BusinessObject::where('key', 'team_log')->firstOrFail();
        $this->relations->validatePayloadRelations($object, $data);
        $writer->handle($object, $data, null, 'team_log.public_create');

        return redirect()->route('team-logs.public.create')->with('status', '班组日报已提交。');
    }
}
