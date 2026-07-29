<?php

namespace App\Http\Controllers;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\ProjectNotification;
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
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $permissions = $user->permissionKeys();
        $canView = fn (string $key): bool => in_array("object.{$key}.view", $permissions, true);
        $viewableKeys = collect($permissions)
            ->filter(fn (string $permission): bool => str_starts_with($permission, 'object.') && str_ends_with($permission, '.view'))
            ->map(fn (string $permission): string => str($permission)->after('object.')->beforeLast('.view')->toString())
            ->values();
        $objects = BusinessObject::query()
            ->whereIn('key', $viewableKeys)
            ->withCount('records')
            ->get()
            ->keyBy('key');
        $isAdmin = $user->roles->contains('name', 'admin');
        $canApproveRequisitions = in_array('object.requisition.update', $permissions, true);
        $visibleProjects = $canView('project') && $objects->has('project')
            ? $this->projectVisibility->scope($objects->get('project')->records()->latest(), $user)->get()
            : collect();

        $scopedCounts = [];
        $count = function (string $key) use ($objects, $visibleProjects, $user, $isAdmin, $canApproveRequisitions, &$scopedCounts): int {
            $object = $objects->get($key);
            if (! $object) {
                return 0;
            }
            if ($key === 'project') {
                return $visibleProjects->count();
            }
            if ($key === 'requisition') {
                return $canApproveRequisitions
                    ? (int) $object->records_count
                    : $object->records()->where('created_by', $user->id)->count();
            }

            $hasProject = collect($object->fields ?? [])->contains(
                fn (array $field) => ($field['key'] ?? null) === 'project_id'
                    && ($field['target'] ?? null) === 'project',
            );
            if (! $hasProject || $isAdmin) {
                return (int) $object->records_count;
            }

            return $scopedCounts[$key] ??= $this->projectVisibility
                ->scopeRecords($object->records(), $object, $user)
                ->count();
        };

        $recordCache = [];
        $recordsFor = function (string $key) use ($objects, $user, $canApproveRequisitions, &$recordCache) {
            $object = $objects->get($key);
            if (! $object) {
                return collect();
            }

            if ($key === 'requisition') {
                $query = $object->records()->latest();
                if (! $canApproveRequisitions) {
                    $query->where('created_by', $user->id);
                }

                return $recordCache[$key] ??= $query->get();
            }

            return $recordCache[$key] ??= $this->projectVisibility
                ->scopeRecords($object->records()->latest(), $object, $user)
                ->get();
        };

        $requisitions = $canView('requisition') ? $recordsFor('requisition') : collect();
        $pendingRequisitions = $requisitions->filter(fn (ObjectRecord $record) => ($record->payload['status'] ?? null) === '待处理')->count();
        $receivables = $canView('receivable') ? $recordsFor('receivable') : collect();
        $money = fn (string $key, ?string $legacyKey = null) => $receivables->sum(
            fn (ObjectRecord $record) => (float) ($record->payload[$key] ?? ($legacyKey ? ($record->payload[$legacyKey] ?? 0) : 0)),
        );
        $unpaidAmount = $canView('receivable')
            ? $receivables->sum(function (ObjectRecord $record): float {
                $payload = $record->payload ?? [];
                $occurred = (int) round((float) ($payload['occurred_amount'] ?? 0) * 100);
                $contract = (int) round((float) ($payload['contract_amount'] ?? 0) * 100);
                $paid = (int) round((float) ($payload['paid_amount'] ?? 0) * 100);
                $base = $occurred > 0 ? $occurred : $contract;

                return max($base - $paid, 0) / 100;
            })
            : 0;
        $stockRisks = ($canView('stock_ledger') ? $recordsFor('stock_ledger') : collect())
            ->filter(fn (ObjectRecord $record) => ($record->payload['below_warn'] ?? '否') === '是')
            ->values();
        if ($stockRisks->isNotEmpty()) {
            $this->relations->preloadLabels($stockRisks, $user);
        }

        $visibleProjectIds = array_flip($visibleProjects->pluck('id')->all());
        $notifications = $canView('project') && $visibleProjectIds
            ? ProjectNotification::query()
                ->where('user_id', $user->id)
                ->whereIn('project_id', array_keys($visibleProjectIds))
                ->active()
                ->with('project')
                ->latest('triggered_at')
                ->get()
            : collect();
        $notificationRisks = $notifications->map(function (ProjectNotification $notification) use ($visibleProjectIds): array {
            $project = $notification->project;
            $payload = $project?->payload ?? [];

            return [
                'id' => $notification->id,
                'type' => $notification->type,
                'type_label' => $notification->type === ProjectNotification::TYPE_CONTRACT ? '合同提醒' : '回款提醒',
                'message' => $notification->type === ProjectNotification::TYPE_CONTRACT
                    ? '项目创建已满三个月，尚未关联合同。'
                    : '项目创建已满三个月，尚未发生回款。',
                'read' => $notification->read_at !== null,
                'project_id' => $project?->id,
                'project_no' => $payload['project_no'] ?? $project?->code ?? '',
                'project_name' => $payload['name'] ?? $project?->title ?? '项目已删除',
                'project_url' => $project && isset($visibleProjectIds[$project->id])
                    ? "/objects/project?record={$project->id}&mode=detail"
                    : null,
            ];
        })->values();

        $stats = collect();
        if ($objects->isNotEmpty()) {
            $stats->push(['label' => '资料表', 'value' => $objects->count(), 'unit' => '张']);
        }
        if ($canView('project')) {
            $stats->push(['label' => '在做项目', 'value' => $visibleProjects->count(), 'unit' => '个']);
        }
        if ($canView('requisition')) {
            $stats->push(['label' => '待处理请购', 'value' => $pendingRequisitions, 'unit' => '条']);
        }
        if ($canView('stock_ledger')) {
            $stats->push(['label' => '库存风险', 'value' => $stockRisks->count(), 'unit' => '项']);
        }

        $boards = collect();
        $operatingItems = collect([
            $canView('customer') ? ['label' => '客户', 'value' => $count('customer'), 'unit' => '个'] : null,
            $canView('contract') ? ['label' => '合同', 'value' => $count('contract'), 'unit' => '份'] : null,
            $canView('project') ? ['label' => '项目', 'value' => $visibleProjects->count(), 'unit' => '个'] : null,
            $canView('drawing') ? ['label' => '图纸', 'value' => $count('drawing'), 'unit' => '份'] : null,
            $canView('work_order') ? ['label' => '生产', 'value' => $count('work_order'), 'unit' => '单'] : null,
            $canView('shipment') ? ['label' => '发货', 'value' => $count('shipment'), 'unit' => '车'] : null,
            $canView('receivable') ? ['label' => '回款', 'value' => $count('receivable'), 'unit' => '笔'] : null,
        ])->filter()->values();
        if ($operatingItems->isNotEmpty()) {
            $boards->push([
                'title' => '经营大盘',
                'desc' => '看项目从客户、合同、图纸、生产、发货到回款走到哪一步。',
                'type' => $canView('project') ? 'flow' : 'cards',
                'items' => $operatingItems,
            ]);
        }

        $inventoryItems = collect([
            $canView('stock_ledger') ? ['label' => '库存记录', 'value' => $count('stock_ledger'), 'unit' => '条'] : null,
            $canView('inbound') ? ['label' => '入库单', 'value' => $count('inbound'), 'unit' => '张'] : null,
            $canView('outbound') ? ['label' => '出库单', 'value' => $count('outbound'), 'unit' => '张'] : null,
            $canView('stock_ledger') ? ['label' => '低库存', 'value' => $stockRisks->count(), 'unit' => '项'] : null,
        ])->filter()->values();
        if ($inventoryItems->isNotEmpty()) {
            $boards->push([
                'title' => '库存大盘',
                'desc' => '看库存够不够，哪些材料需要注意。',
                'type' => 'cards',
                'items' => $inventoryItems,
            ]);
        }

        $procurementItems = collect([
            $canView('requisition') ? ['label' => '请购单', 'value' => $count('requisition'), 'unit' => '张'] : null,
            $canView('requisition') ? ['label' => '待处理', 'value' => $pendingRequisitions, 'unit' => '张'] : null,
            $canView('purchase') ? ['label' => '采购单', 'value' => $count('purchase'), 'unit' => '张'] : null,
            $canView('material') ? ['label' => '物料', 'value' => $count('material'), 'unit' => '种'] : null,
        ])->filter()->values();
        if ($procurementItems->isNotEmpty()) {
            $boards->push([
                'title' => '采购大盘',
                'desc' => '看请购、采购和到货处理情况。',
                'type' => 'cards',
                'items' => $procurementItems,
            ]);
        }

        if ($canView('receivable')) {
            $boards->push([
                'title' => '财务大盘',
                'desc' => '看合同、开票、回款和欠款。',
                'type' => 'cards',
                'items' => [
                    ['label' => '合同金额', 'value' => round($money('contract_amount') / 10000, 2), 'unit' => '万元'],
                    ['label' => '已开票', 'value' => round($money('invoiced_amount', 'invoice_amount') / 10000, 2), 'unit' => '万元'],
                    ['label' => '已回款', 'value' => round($money('paid_amount') / 10000, 2), 'unit' => '万元'],
                    ['label' => '欠款', 'value' => round($unpaidAmount / 10000, 2), 'unit' => '万元'],
                ],
            ]);
        }

        $shipmentWeights = collect();
        if ($visibleProjects->isNotEmpty()) {
            $shipment = BusinessObject::where('key', 'shipment')->first();
            $shipmentWeights = $shipment?->records()
                ->whereIn('payload->project_id', $visibleProjects->pluck('id'))
                ->whereNotNull('payload->ship_date')
                ->where('payload->ship_date', '!=', '')
                ->get()
                ->groupBy(fn (ObjectRecord $record) => $record->payload['project_id'] ?? '')
                ->map(fn ($records) => round($records->sum(
                    fn (ObjectRecord $record) => (float) ($record->payload['qty_ton'] ?? 0),
                ), 4)) ?? collect();
        }

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'boards' => $boards,
            'projectFlows' => $visibleProjects
                ->take(50)
                ->map(fn (ObjectRecord $record) => $this->projectFlow(
                    $record,
                    (float) $shipmentWeights->get($record->id, 0),
                ))
                ->values(),
            'recentProjects' => $visibleProjects->take(5)->values(),
            'stockRisks' => $stockRisks
                ->take(8)
                ->map(fn (ObjectRecord $record) => $this->relations->formatRecord($record))
                ->values(),
            'notificationSummary' => [
                'contract' => $notifications->where('type', ProjectNotification::TYPE_CONTRACT)->count(),
                'payment' => $notifications->where('type', ProjectNotification::TYPE_PAYMENT)->count(),
            ],
            'notificationRisks' => $notificationRisks,
            'notificationUnreadCount' => $notifications->whereNull('read_at')->count(),
        ]);
    }

    private function projectFlow(ObjectRecord $project, float $shippedWeight = 0): array
    {
        $steps = ['客户', '合同', '项目', '图纸', '生产', '发货', '回款'];
        $stage = (string) ($project->payload['stage'] ?? '');
        $index = $this->flowIndex($stage);
        $parallel = $stage === '发货签收';

        return [
            'id' => $project->id,
            'label' => trim($project->code.' · '.$project->title),
            'current_stage' => $stage,
            'current_step' => $parallel ? '发货、回款并行' : $steps[$index],
            'shipped_weight_ton' => $shippedWeight,
            'steps' => collect($steps)->map(function (string $label, int $step) use ($index, $parallel): array {
                $status = $step < $index ? 'done' : ($step === $index ? 'current' : 'todo');
                if ($parallel && $label === '发货') {
                    $status = 'parallel-shipment';
                }
                if ($parallel && $label === '回款') {
                    $status = 'parallel-payment';
                }

                return ['label' => $label, 'status' => $status];
            })->all(),
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
