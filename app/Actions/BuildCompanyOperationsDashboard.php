<?php

namespace App\Actions;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\ProjectNotification;
use App\Models\User;
use App\Support\ObjectRelations;
use App\Support\ProjectVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

class BuildCompanyOperationsDashboard
{
    private const OBJECT_KEYS = [
        'project',
        'contract',
        'receivable',
        'invoice',
        'tender',
        'work_order',
        'shipment',
    ];

    private const PROJECT_STATUSES = ['投标中', '已中标', '已拿到加工函', '合同签署', '已完成'];

    private const ACTIVE_PROJECT_STATUSES = ['投标中', '已中标', '已拿到加工函', '合同签署'];

    private const TENDER_STATUSES = ['跟踪中', '已报名', '已购标书', '制作中', '已递交', '已中标', '未中标', '已放弃'];

    private const WORK_ORDER_STATUSES = ['未开始', '生产中', '异常暂停', '已完成'];

    private const KPI_ORDER = ['occurred_amount', 'collection_rate', 'tender_win_rate', 'current_debt'];

    public function __construct(
        private ProjectVisibility $projectVisibility,
        private ObjectRelations $relations,
    ) {}

    /** @return array<string, mixed> */
    public function handle(User $user): array
    {
        $objects = BusinessObject::query()
            ->whereIn('key', self::OBJECT_KEYS)
            ->get()
            ->keyBy('key');
        $records = collect(self::OBJECT_KEYS)
            ->mapWithKeys(fn (string $key): array => [
                $key => $this->recordsFor($objects->get($key), $user),
            ]);
        $projects = $records->get('project') ?? collect();
        $contracts = $records->get('contract') ?? collect();
        $notifications = $this->notifications($projects, $user);

        $this->relations->preloadLabels($projects->take(8), $user);

        $projectProgresses = $this->projectProgresses($projects);
        $cockpit = [
            'meta' => [
                'scope' => $this->isAdmin($user) ? '公司全量' : '我的可见范围',
                'as_of' => now()->toISOString(),
            ],
            'kpis' => [],
            'panels' => [],
            'project_progress' => $projectProgresses[0] ?? null,
            'project_progresses' => $projectProgresses,
        ];

        if ($records->get('receivable') instanceof Collection) {
            $financeKpis = $this->financeKpis($records->get('receivable'));
            $cockpit['kpis'] = [...$cockpit['kpis'], ...$financeKpis['kpis']];
        }

        if ($records->get('tender') instanceof Collection) {
            $tender = $this->tenderPanel($records->get('tender'));
            $cockpit['kpis'][] = $tender['kpi'];
            $cockpit['panels']['tender_pipeline'] = $tender['panel'];
        }

        $cashFlow = $this->cashFlowPanel(
            $records->get('contract'),
            $records->get('receivable'),
            $records->get('invoice'),
        );
        if ($cashFlow !== null) {
            $cockpit['panels']['cash_flow'] = $cashFlow;
        }

        if ($records->get('project') instanceof Collection) {
            $cockpit['panels']['project_status'] = $this->projectStatusPanel($records->get('project'));
        }

        $productionDelivery = $this->productionDeliveryPanel(
            $records->get('work_order'),
            $records->get('shipment'),
        );
        if ($productionDelivery !== null) {
            $cockpit['panels']['production_delivery'] = $productionDelivery;
        }

        $cockpit['kpis'] = collect($cockpit['kpis'])
            ->sortBy(function (array $kpi): int {
                $position = array_search($kpi['key'], self::KPI_ORDER, true);

                return $position === false ? count(self::KPI_ORDER) : $position;
            })
            ->values()
            ->all();

        return [
            'stats' => $this->legacyStats($projects, $contracts, $notifications),
            'statusSummary' => $projects
                ->groupBy(fn (ObjectRecord $project): string => (string) ($project->payload['overall_status'] ?? '状态未维护'))
                ->map->count(),
            'recentProjects' => $projects
                ->take(8)
                ->map(fn (ObjectRecord $project): array => $this->relations->formatRecord($project, $user))
                ->values(),
            'notificationRisks' => $notifications
                ->take(8)
                ->map(fn (ProjectNotification $notification): array => $this->notification($notification))
                ->values(),
            'notificationUnreadCount' => $notifications->whereNull('read_at')->count(),
            'cockpit' => $cockpit,
        ];
    }

    /** @return Collection<int, ObjectRecord>|null */
    private function recordsFor(?BusinessObject $object, User $user): ?Collection
    {
        if (! $object || ! $user->canDo("object.{$object->key}.view")) {
            return null;
        }

        $records = $this->projectVisibility
            ->scopeRecords($object->records()->latest(), $object, $user)
            ->get();
        $records->each(fn (ObjectRecord $record): ObjectRecord => $record->setRelation('businessObject', $object));

        return $records;
    }

    /**
     * @param  Collection<int, ObjectRecord>  $projects
     * @return Collection<int, ProjectNotification>
     */
    private function notifications(Collection $projects, User $user): Collection
    {
        $projectIds = $projects->pluck('id');

        if ($projectIds->isEmpty()) {
            return collect();
        }

        return ProjectNotification::query()
            ->where('user_id', $user->id)
            ->whereIn('project_id', $projectIds)
            ->active()
            ->with('project')
            ->latest('triggered_at')
            ->get();
    }

    /**
     * @param  Collection<int, ObjectRecord>  $projects
     * @param  Collection<int, ObjectRecord>  $contracts
     * @param  Collection<int, ProjectNotification>  $notifications
     * @return array<int, array{label: string, value: int|float, unit: string}>
     */
    private function legacyStats(Collection $projects, Collection $contracts, Collection $notifications): array
    {
        return [
            ['label' => '业务项目', 'value' => $projects->count(), 'unit' => '个'],
            ['label' => '合同记录', 'value' => $contracts->count(), 'unit' => '份'],
            ['label' => '待处理提醒', 'value' => $notifications->count(), 'unit' => '项'],
            [
                'label' => '未回款金额',
                'value' => round($projects->sum(fn (ObjectRecord $project): float => (float) ($project->payload['unpaid_amount'] ?? 0)) / 10000, 2),
                'unit' => '万元',
            ],
        ];
    }

    /**
     * @param  Collection<int, ObjectRecord>  $receivables
     * @return array{kpis: array<int, array<string, mixed>>}
     */
    private function financeKpis(Collection $receivables): array
    {
        $occurredTotal = 0.0;
        $occurredValid = 0;
        $weightedPaid = 0.0;
        $weightedBase = 0.0;
        $debt = 0.0;
        $ledgerValid = 0;
        $debtProjects = 0;

        foreach ($receivables as $receivable) {
            $payload = $receivable->payload;
            $occurred = $this->nonNegativeNumber($payload['occurred_amount'] ?? null);
            if ($occurred !== null) {
                $occurredTotal += $occurred;
                $occurredValid++;
            }

            $contract = $this->nonNegativeNumber($payload['contract_amount'] ?? null);
            $paid = $this->nonNegativeNumber($payload['paid_amount'] ?? null);
            $base = $occurred !== null && $occurred > 0 ? $occurred : $contract;
            if ($base === null || $paid === null) {
                continue;
            }

            $ledgerValid++;
            $weightedBase += $base;
            $weightedPaid += min($paid, $base);
            $projectDebt = max($base - $paid, 0);
            $debt += $projectDebt;
            if ($projectDebt > 0) {
                $debtProjects++;
            }
        }

        $rate = $weightedBase > 0 ? round($weightedPaid / $weightedBase * 100, 1) : null;

        return [
            'kpis' => [
                [
                    'key' => 'occurred_amount',
                    'label' => '累计实际发生金额',
                    'value' => $occurredValid > 0 ? $occurredTotal : null,
                    'format' => 'amount',
                    'hint' => '产值口径',
                    'coverage' => ['valid' => $occurredValid, 'total' => $receivables->count()],
                ],
                [
                    'key' => 'collection_rate',
                    'label' => '加权回款率',
                    'value' => $rate,
                    'format' => 'percentage',
                    'hint' => $weightedBase > 0
                        ? sprintf('%.1f / %.1f 万元', $weightedPaid / 10000, $weightedBase / 10000)
                        : '分母为 0，暂不可计算',
                    'coverage' => ['valid' => $ledgerValid, 'total' => $receivables->count()],
                ],
                [
                    'key' => 'current_debt',
                    'label' => '当前欠款',
                    'value' => $ledgerValid > 0 ? $debt : null,
                    'format' => 'amount',
                    'hint' => "{$debtProjects} 个项目待跟进",
                    'coverage' => ['valid' => $ledgerValid, 'total' => $receivables->count()],
                ],
            ],
        ];
    }

    /**
     * @param  Collection<int, ObjectRecord>|null  $contracts
     * @param  Collection<int, ObjectRecord>|null  $receivables
     * @param  Collection<int, ObjectRecord>|null  $invoices
     * @return array<string, mixed>|null
     */
    private function cashFlowPanel(?Collection $contracts, ?Collection $receivables, ?Collection $invoices): ?array
    {
        if ($contracts === null && $receivables === null && $invoices === null) {
            return null;
        }

        $series = [];
        if ($contracts !== null) {
            $series[] = $this->amountSeries('contract', '合同金额', $contracts, 'amount');
        }
        if ($receivables !== null) {
            $series[] = $this->amountSeries('occurred', '实际发生', $receivables, 'occurred_amount');
            $series[] = $this->amountSeries('reconciled', '已对账', $receivables, 'reconciled_amount');
        }
        if ($invoices !== null) {
            $issuedInvoices = $invoices->filter(
                fn (ObjectRecord $invoice): bool => ($invoice->payload['status'] ?? null) === '已开票',
            )->values();
            $series[] = $this->amountSeries('invoiced', '已开票', $issuedInvoices, 'amount', $invoices->count());
        }
        if ($receivables !== null) {
            $series[] = $this->amountSeries('paid', '已回款', $receivables, 'paid_amount');
        }

        return [
            'series' => $series,
            'url' => $receivables !== null ? '/objects/project' : ($contracts !== null ? '/objects/contract' : null),
        ];
    }

    /**
     * @param  Collection<int, ObjectRecord>  $records
     * @return array<string, mixed>
     */
    private function amountSeries(
        string $key,
        string $label,
        Collection $records,
        string $field,
        ?int $coverageTotal = null,
    ): array {
        $values = $records
            ->map(fn (ObjectRecord $record): ?float => $this->nonNegativeNumber($record->payload[$field] ?? null))
            ->filter(fn (?float $value): bool => $value !== null);

        return [
            'key' => $key,
            'label' => $label,
            'value' => $values->isNotEmpty() ? $values->sum() : null,
            'coverage' => ['valid' => $values->count(), 'total' => $coverageTotal ?? $records->count()],
        ];
    }

    /**
     * @param  Collection<int, ObjectRecord>  $tenders
     * @return array{kpi: array<string, mixed>, panel: array<string, mixed>}
     */
    private function tenderPanel(Collection $tenders): array
    {
        $counts = collect(self::TENDER_STATUSES)
            ->mapWithKeys(fn (string $status): array => [
                $status => $tenders->where('payload.status', $status)->count(),
            ]);
        $decided = $counts->only(['已递交', '已中标', '未中标'])->sum();
        $won = $counts->get('已中标', 0);
        $budgetValues = $tenders
            ->map(fn (ObjectRecord $tender): ?float => $this->nonNegativeNumber($tender->payload['budget_amount'] ?? null))
            ->filter(fn (?float $value): bool => $value !== null);

        return [
            'kpi' => [
                'key' => 'tender_win_rate',
                'label' => '投标中标率',
                'value' => $decided > 0 ? round($won / $decided * 100, 1) : null,
                'format' => 'percentage',
                'hint' => "{$won} 中标 / {$decided} 纳入口径",
                'coverage' => ['valid' => $decided, 'total' => $tenders->count()],
            ],
            'panel' => [
                'statuses' => collect(self::TENDER_STATUSES)
                    ->map(fn (string $status): array => ['status' => $status, 'count' => $counts->get($status, 0)])
                    ->values(),
                'records_count' => $tenders->count(),
                'budget_total' => $budgetValues->isNotEmpty() ? $budgetValues->sum() : null,
                'budget_coverage' => ['valid' => $budgetValues->count(), 'total' => $tenders->count()],
                'url' => '/objects/tender',
            ],
        ];
    }

    /**
     * @param  Collection<int, ObjectRecord>  $projects
     * @return array<string, mixed>
     */
    private function projectStatusPanel(Collection $projects): array
    {
        $counts = collect(self::PROJECT_STATUSES)
            ->mapWithKeys(fn (string $status): array => [
                $status => $projects->where('payload.overall_status', $status)->count(),
            ]);
        $activeTotal = $counts->only(self::ACTIVE_PROJECT_STATUSES)->sum();
        $knownTotal = $counts->sum();

        return [
            'active_total' => $activeTotal,
            'statuses' => collect(self::ACTIVE_PROJECT_STATUSES)
                ->map(function (string $status) use ($counts, $activeTotal): array {
                    $count = $counts->get($status, 0);

                    return [
                        'status' => $status,
                        'count' => $count,
                        'percentage' => $activeTotal > 0 ? round($count / $activeTotal * 100, 1) : null,
                    ];
                })
                ->values(),
            'completed_count' => $counts->get('已完成', 0),
            'unmaintained_count' => max($projects->count() - $knownTotal, 0),
            'records_count' => $projects->count(),
            'url' => '/objects/project',
        ];
    }

    /**
     * @param  Collection<int, ObjectRecord>|null  $workOrders
     * @param  Collection<int, ObjectRecord>|null  $shipments
     * @return array<string, mixed>|null
     */
    private function productionDeliveryPanel(?Collection $workOrders, ?Collection $shipments): ?array
    {
        if ($workOrders === null && $shipments === null) {
            return null;
        }

        $panel = [];
        if ($workOrders !== null) {
            $productionValues = $workOrders
                ->map(fn (ObjectRecord $record): ?float => $this->nonNegativeNumber($record->payload['production_qty_ton'] ?? null))
                ->filter(fn (?float $value): bool => $value !== null);
            $plannedValues = $workOrders
                ->map(fn (ObjectRecord $record): ?float => $this->nonNegativeNumber($record->payload['weight'] ?? null))
                ->filter(fn (?float $value): bool => $value !== null);

            $panel['production'] = [
                'total_ton' => $productionValues->isNotEmpty() ? $productionValues->sum() : null,
                'planned_ton' => $plannedValues->isNotEmpty() ? $plannedValues->sum() : null,
                'coverage' => ['valid' => $productionValues->count(), 'total' => $workOrders->count()],
                'statuses' => collect(self::WORK_ORDER_STATUSES)
                    ->map(fn (string $status): array => [
                        'status' => $status,
                        'count' => $workOrders->where('payload.status', $status)->count(),
                    ])
                    ->values(),
                'url' => null,
            ];
        }

        if ($shipments !== null) {
            $panel['shipment'] = $this->shipmentSummary($shipments);
        }

        return $panel;
    }

    /**
     * @param  Collection<int, ObjectRecord>  $shipments
     * @return array<string, mixed>
     */
    private function shipmentSummary(Collection $shipments): array
    {
        $totalTon = 0.0;
        $validQuantityCount = 0;
        $invalidQuantityCount = 0;
        $datedCount = 0;
        $undatedTon = 0.0;
        $monthly = collect();

        foreach ($shipments as $shipment) {
            $quantity = $this->nonNegativeNumber($shipment->payload['qty_ton'] ?? null);
            if ($quantity === null) {
                $invalidQuantityCount++;

                continue;
            }

            $validQuantityCount++;
            $totalTon += $quantity;
            $date = $this->date($shipment->payload['ship_date'] ?? null);
            if ($date === null) {
                $undatedTon += $quantity;

                continue;
            }

            $datedCount++;
            $month = $date->format('Y-m');
            $monthly->put($month, (float) $monthly->get($month, 0) + $quantity);
        }

        return [
            'total_ton' => $validQuantityCount > 0 ? $totalTon : null,
            'coverage' => ['valid' => $validQuantityCount, 'total' => $shipments->count()],
            'trend_coverage' => ['valid' => $datedCount, 'total' => $validQuantityCount],
            'invalid_quantity_count' => $invalidQuantityCount,
            'undated_ton' => $undatedTon,
            'monthly' => $monthly
                ->sortKeys()
                ->map(fn (float $ton, string $month): array => [
                    'month' => $month,
                    'label' => CarbonImmutable::createFromFormat('!Y-m', $month)->format('Y年n月'),
                    'ton' => $ton,
                ])
                ->values(),
            'url' => null,
        ];
    }

    /**
     * @param  Collection<int, ObjectRecord>  $projects
     * @return array<string, mixed>|null
     */
    /**
     * @param  Collection<int, ObjectRecord>  $projects
     * @return array<int, array<string, mixed>>
     */
    private function projectProgresses(Collection $projects): array
    {
        return $projects
            ->map(fn (ObjectRecord $project): array => $this->projectProgress($project))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function projectProgress(ObjectRecord $project): array
    {
        $status = is_string($project->payload['overall_status'] ?? null)
            ? $project->payload['overall_status']
            : null;
        $currentIndex = $status !== null ? array_search($status, self::PROJECT_STATUSES, true) : false;

        return [
            'project_id' => $project->id,
            'project_no' => $project->payload['project_no'] ?? $project->code,
            'project_name' => $project->payload['name'] ?? $project->title,
            'current_status' => $status ?? '状态未维护',
            'steps' => collect(self::PROJECT_STATUSES)
                ->map(function (string $step, int $index) use ($currentIndex): array {
                    $state = $currentIndex === false
                        ? 'todo'
                        : ($index < $currentIndex ? 'done' : ($index === $currentIndex ? 'current' : 'todo'));

                    return ['label' => $step, 'state' => $state];
                })
                ->values(),
            'url' => "/objects/project?record={$project->id}&mode=detail",
        ];
    }

    private function nonNegativeNumber(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) && $number >= 0 ? $number : null;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function isAdmin(User $user): bool
    {
        $user->loadMissing('roles');

        return $user->roles->contains('name', 'admin');
    }

    /** @return array<string, mixed> */
    private function notification(ProjectNotification $notification): array
    {
        $labels = [
            ProjectNotification::TYPE_BID => '投标进度提醒',
            ProjectNotification::TYPE_PROCESSING_LETTER => '加工函提醒',
            ProjectNotification::TYPE_SIGNATURE => '合同签署提醒',
            ProjectNotification::TYPE_PAYMENT => '回款提醒',
        ];
        $project = $notification->project;

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'type_label' => $labels[$notification->type] ?? '项目提醒',
            'read' => $notification->read_at !== null,
            'project_name' => $project?->payload['name'] ?? $project?->title ?? '项目已删除',
            'project_no' => $project?->payload['project_no'] ?? $project?->code ?? '',
            'project_url' => $project ? "/objects/project?record={$project->id}&mode=detail" : null,
        ];
    }
}
