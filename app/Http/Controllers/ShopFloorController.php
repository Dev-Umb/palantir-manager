<?php

namespace App\Http\Controllers;

use App\Actions\CreateObjectRecord;
use App\Models\BusinessObject;
use App\Support\ObjectRelations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;
use Inertia\Response;

class ShopFloorController extends Controller
{
    public function __construct(private ObjectRelations $relations) {}

    public function teamLogCreate(Request $request): Response
    {
        return $this->renderTeamLogForm($request);
    }

    public function publicTeamLogCreate(Request $request): Response
    {
        return $this->renderTeamLogForm($request, publicForm: true);
    }

    private function renderTeamLogForm(Request $request, bool $publicForm = false): Response
    {
        $object = BusinessObject::where('key', 'team_log')->firstOrFail();
        $options = $this->relations->optionsFor($object, user: $request->user());

        return Inertia::render('TeamLogs/Create', [
            'projects' => $options['project_id']['items'] ?? [],
            'teams' => $options['team_id']['items'] ?? [],
            'materials' => $this->relations->optionsForObjectKey('material'),
            'searchUrls' => [
                'project_id' => $publicForm ? '' : ($options['project_id']['search_url'] ?? ''),
                'team_id' => $publicForm ? '' : ($options['team_id']['search_url'] ?? ''),
                'material_id' => $publicForm
                    ? route('requisitions.public.material-options', absolute: false)
                    : route('relation-options.index', [
                        'source_object' => 'requisition',
                        'field' => 'material_id',
                    ], false),
            ],
            'submitUrl' => $publicForm ? $request->fullUrl() : route('team-logs.store'),
            'publicForm' => $publicForm,
        ]);
    }

    public function teamLogStore(Request $request, CreateObjectRecord $writer): RedirectResponse
    {
        return $this->storeTeamLog($request, $writer);
    }

    public function publicTeamLogStore(Request $request, CreateObjectRecord $writer): RedirectResponse
    {
        return $this->storeTeamLog($request, $writer, publicForm: true);
    }

    private function storeTeamLog(
        Request $request,
        CreateObjectRecord $writer,
        bool $publicForm = false,
    ): RedirectResponse {
        $data = $request->validate([
            'project_id' => ['required', 'string'],
            'team_id' => ['required', 'string'],
            'status' => ['required', Rule::in(['开始生产', '生产中', '异常暂停', '完成任务'])],
            'process' => ['required', Rule::in(['切割', '焊接', '总装', '打磨', '其他'])],
            'completed_qty' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', Rule::in(['件', '套', 'kg', '吨', '张', '根'])],
            'exception_type' => ['required', Rule::in(['无', '缺料', '图纸问题', '设备故障', '质量问题', '人员不足', '其他'])],
            'part_name' => ['nullable', 'string', 'max:160'],
            'work_date' => ['nullable', 'date'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'attachment' => ['nullable', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(20 * 1024)],
            'shortage_material_id' => [Rule::requiredIf($request->input('exception_type') === '缺料'), 'nullable', 'string'],
            'shortage_qty' => [Rule::requiredIf($request->input('exception_type') === '缺料'), 'nullable', 'numeric', 'min:0.01'],
            'shortage_unit' => [Rule::requiredIf($request->input('exception_type') === '缺料'), 'nullable', Rule::in(['吨', 'kg', '张', '根'])],
        ]);

        $object = BusinessObject::where('key', 'team_log')->firstOrFail();
        if ($request->hasFile('attachment')) {
            $data['attachment'] = $request->file('attachment')->store('attachments', 'local');
        }
        if ($data['exception_type'] !== '无') {
            $data['status'] = '异常暂停';
        }

        DB::transaction(function () use ($object, $data, $request, $writer): void {
            $this->relations->lockReferenceGraph();
            $reportPayload = Arr::except($data, ['shortage_material_id', 'shortage_qty', 'shortage_unit']);
            $this->relations->validatePayloadRelations($object, $reportPayload, $request->user());
            $writer->handle($object, $reportPayload, $request->user(), 'team_log.create');

            if ($data['exception_type'] !== '缺料') {
                return;
            }

            $requisition = BusinessObject::where('key', 'requisition')->firstOrFail();
            $writer->handle($requisition, [
                'requester' => '生产',
                'material_id' => $data['shortage_material_id'],
                'qty' => $data['shortage_qty'],
                'unit' => $data['shortage_unit'],
                'project_id' => $data['project_id'],
                'urgency' => '紧急',
                'reason' => collect(['现场报工缺料', $data['part_name'] ?? null, $data['remark'] ?? null])
                    ->filter()
                    ->implode(' · '),
                'status' => '待处理',
            ], $request->user(), 'team_log.shortage_requisition');
        });

        $message = $data['exception_type'] === '缺料'
            ? '报工已提交，并已自动生成紧急采购申请。'
            : '现场报工已提交。';

        if ($publicForm) {
            return redirect()->to($request->fullUrl())->with('status', $message);
        }

        return redirect()->route('team-logs.create')->with('status', $message);
    }
}
