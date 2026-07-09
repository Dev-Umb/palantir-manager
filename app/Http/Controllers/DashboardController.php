<?php

namespace App\Http\Controllers;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Support\ObjectRelations;
use App\Support\ProjectVisibility;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private ProjectVisibility $projectVisibility,
        private ObjectRelations $relations,
    )
    {
    }

    public function __invoke(Request $request): Response
    {
        $objects = BusinessObject::withCount('records')->get()->keyBy('key');
        $count = fn (string $key) => (int) ($objects->get($key)?->records_count ?? 0);
        $visibleProjects = $this->projectVisibility
            ->scope(ObjectRecord::whereRelation('businessObject', 'key', 'project')->latest(), $request->user())
            ->get();

        $dashboardObjects = collect(['requisition', 'receivable', 'stock_ledger'])
            ->map(fn (string $key) => $objects->get($key))
            ->filter();
        $dashboardRecords = $dashboardObjects->isEmpty()
            ? collect()
            : ObjectRecord::whereIn('business_object_id', $dashboardObjects->pluck('id'))->latest()->get()->groupBy('business_object_id');
        $recordsFor = fn (string $key) => $objects->has($key)
            ? ($dashboardRecords->get($objects->get($key)->id) ?? collect())
            : collect();

        $requisitions = $recordsFor('requisition');
        $pendingRequisitions = $requisitions->filter(fn (ObjectRecord $record) => ($record->payload['status'] ?? null) === '待处理')->count();
        $receivables = $recordsFor('receivable');
        $money = fn (string $key) => $receivables->sum(fn (ObjectRecord $record) => (float) ($record->payload[$key] ?? 0));
        $stockRisks = $recordsFor('stock_ledger')
            ->filter(fn (ObjectRecord $record) => ($record->payload['below_warn'] ?? '否') === '是')
            ->values();
        $this->relations->preloadLabels($stockRisks);

        return Inertia::render('Dashboard', [
            'stats' => [
                ['label' => '资料表', 'value' => $objects->count(), 'unit' => '张'],
                ['label' => '在做项目', 'value' => $visibleProjects->count(), 'unit' => '个'],
                ['label' => '待处理请购', 'value' => $pendingRequisitions, 'unit' => '条'],
                ['label' => '库存风险', 'value' => $stockRisks->count(), 'unit' => '项'],
            ],
            'boards' => [
                [
                    'title' => '经营大盘',
                    'desc' => '看项目从客户、合同、图纸、生产、发货到回款走到哪一步。',
                    'type' => 'flow',
                    'items' => [
                        ['label' => '客户', 'value' => $count('customer'), 'unit' => '个'],
                        ['label' => '合同', 'value' => $count('contract'), 'unit' => '份'],
                        ['label' => '项目', 'value' => $visibleProjects->count(), 'unit' => '个'],
                        ['label' => '图纸', 'value' => $count('drawing'), 'unit' => '份'],
                        ['label' => '生产', 'value' => $count('work_order'), 'unit' => '单'],
                        ['label' => '发货', 'value' => $count('shipment'), 'unit' => '车'],
                        ['label' => '回款', 'value' => $count('receivable'), 'unit' => '笔'],
                    ],
                ],
                [
                    'title' => '库存大盘',
                    'desc' => '看库存够不够，哪些材料需要注意。',
                    'type' => 'cards',
                    'items' => [
                        ['label' => '库存记录', 'value' => $count('stock_ledger'), 'unit' => '条'],
                        ['label' => '入库单', 'value' => $count('inbound'), 'unit' => '张'],
                        ['label' => '出库单', 'value' => $count('outbound'), 'unit' => '张'],
                        ['label' => '低库存', 'value' => $stockRisks->count(), 'unit' => '项'],
                    ],
                ],
                [
                    'title' => '采购大盘',
                    'desc' => '看请购、采购和到货处理情况。',
                    'type' => 'cards',
                    'items' => [
                        ['label' => '请购单', 'value' => $count('requisition'), 'unit' => '张'],
                        ['label' => '待处理', 'value' => $pendingRequisitions, 'unit' => '张'],
                        ['label' => '采购单', 'value' => $count('purchase'), 'unit' => '张'],
                        ['label' => '物料', 'value' => $count('material'), 'unit' => '种'],
                    ],
                ],
                [
                    'title' => '财务大盘',
                    'desc' => '看合同、开票、回款和欠款。',
                    'type' => 'cards',
                    'items' => [
                        ['label' => '合同金额', 'value' => round($money('contract_amount') / 10000, 2), 'unit' => '万元'],
                        ['label' => '已开票', 'value' => round($money('invoice_amount') / 10000, 2), 'unit' => '万元'],
                        ['label' => '已回款', 'value' => round($money('paid_amount') / 10000, 2), 'unit' => '万元'],
                        ['label' => '欠款', 'value' => round($money('unpaid') / 10000, 2), 'unit' => '万元'],
                    ],
                ],
            ],
            'projectFlows' => $visibleProjects
                ->take(50)
                ->map(fn (ObjectRecord $record) => $this->projectFlow($record))
                ->values(),
            'recentProjects' => $visibleProjects->take(5)->values(),
            'stockRisks' => $stockRisks
                ->take(8)
                ->map(fn (ObjectRecord $record) => $this->relations->formatRecord($record))
                ->values(),
        ]);
    }

    private function projectFlow(ObjectRecord $project): array
    {
        $steps = ['客户', '合同', '项目', '图纸', '生产', '发货', '回款'];
        $index = $this->flowIndex((string) ($project->payload['stage'] ?? ''));

        return [
            'id' => $project->id,
            'label' => trim($project->code.' · '.$project->title),
            'current_stage' => $project->payload['stage'] ?? '',
            'current_step' => $steps[$index],
            'steps' => collect($steps)->map(fn (string $label, int $step) => [
                'label' => $label,
                'status' => $step < $index ? 'done' : ($step === $index ? 'current' : 'todo'),
            ])->all(),
        ];
    }

    private function flowIndex(string $stage): int
    {
        return match ($stage) {
            '合同录入' => 1,
            '技术确认', '正在对接', '设计出图' => 3,
            '生产加工', '采购执行' => 4,
            '成品发货', '发货签收' => 5,
            '对账回款', '项目完成' => 6,
            default => 2,
        };
    }
}
