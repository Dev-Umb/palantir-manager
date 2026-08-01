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
        $projectObject = BusinessObject::where('key', 'project')->first();
        $contractObject = BusinessObject::where('key', 'contract')->first();
        $projects = $projectObject && $user->canDo('object.project.view')
            ? $this->projectVisibility->scope($projectObject->records()->latest(), $user)->get()
            : collect();
        $projectIds = $projects->pluck('id');
        $contracts = $contractObject && $user->canDo('object.contract.view')
            ? $this->projectVisibility->scopeRecords($contractObject->records()->latest(), $contractObject, $user)->get()
            : collect();
        $notifications = $projectIds->isNotEmpty()
            ? ProjectNotification::query()
                ->where('user_id', $user->id)
                ->whereIn('project_id', $projectIds)
                ->active()
                ->with('project')
                ->latest('triggered_at')
                ->get()
            : collect();

        $this->relations->preloadLabels($projects->take(8), $user);

        return Inertia::render('Dashboard', [
            'stats' => [
                ['label' => '业务项目', 'value' => $projects->count(), 'unit' => '个'],
                ['label' => '合同记录', 'value' => $contracts->count(), 'unit' => '份'],
                ['label' => '待处理提醒', 'value' => $notifications->count(), 'unit' => '项'],
                ['label' => '未回款金额', 'value' => round($projects->sum(fn (ObjectRecord $project): float => (float) ($project->payload['unpaid_amount'] ?? 0)) / 10000, 2), 'unit' => '万元'],
            ],
            'statusSummary' => $projects
                ->groupBy(fn (ObjectRecord $project): string => (string) ($project->payload['overall_status'] ?? '投标中'))
                ->map->count(),
            'recentProjects' => $projects->take(8)->map(fn (ObjectRecord $project): array => $this->relations->formatRecord($project, $user))->values(),
            'notificationRisks' => $notifications->take(8)->map(fn (ProjectNotification $notification): array => $this->notification($notification))->values(),
            'notificationUnreadCount' => $notifications->whereNull('read_at')->count(),
        ]);
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
