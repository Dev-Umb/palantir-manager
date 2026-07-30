<?php

namespace App\Http\Controllers;

use App\Actions\SyncProjectNotifications;
use App\Models\AuditLog;
use App\Models\ProjectNotification;
use App\Support\ProjectVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function __construct(
        private SyncProjectNotifications $sync,
        private ProjectVisibility $projectVisibility,
    ) {}

    public function index(Request $request): Response
    {
        $this->sync->handle();

        $user = $request->user();
        $visibleProjectIds = array_flip($this->projectVisibility->visibleProjectIds($user));
        $notifications = ProjectNotification::query()
            ->where('user_id', $user->id)
            ->with('project')
            ->orderBy('status')
            ->latest('triggered_at')
            ->paginate(20)
            ->withQueryString()
            ->through(function (ProjectNotification $notification) use ($visibleProjectIds): array {
                $project = $notification->project;
                $payload = $project?->payload ?? [];
                $canViewProject = $project && isset($visibleProjectIds[$project->id]);

                return [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'type_label' => $notification->type === ProjectNotification::TYPE_CONTRACT ? '合同逾期' : '回款逾期',
                    'message' => $this->message($notification),
                    'status' => $notification->status,
                    'read_at' => $notification->read_at?->toISOString(),
                    'resolved_at' => $notification->resolved_at?->toISOString(),
                    'triggered_at' => $notification->triggered_at?->toISOString(),
                    'occurrences' => $notification->occurrences,
                    'project' => $project ? [
                        'id' => $project->id,
                        'code' => $project->code,
                        'name' => $payload['name'] ?? $project->title ?? $project->code,
                        'project_no' => $payload['project_no'] ?? $project->code,
                    ] : null,
                    'can_view_project' => (bool) $canViewProject,
                    'project_url' => $canViewProject ? "/objects/project?record={$project->id}&mode=detail" : null,
                ];
            });

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'unreadCount' => ProjectNotification::query()
                ->where('user_id', $user->id)
                ->active()
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function markRead(Request $request, ProjectNotification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
            $this->auditRead($request, $notification);
        }

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $now = now();
        $count = ProjectNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => $now, 'updated_at' => $now]);

        if ($count > 0) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'notification.read_all',
                'subject_type' => 'project_notification',
                'subject_id' => null,
                'payload' => ['count' => $count],
            ]);
        }

        return back();
    }

    private function message(ProjectNotification $notification): string
    {
        $projectName = $notification->project?->payload['name']
            ?? $notification->project?->title
            ?? $notification->project?->code
            ?? '已删除项目';

        return $notification->type === ProjectNotification::TYPE_CONTRACT
            ? "项目「{$projectName}」创建已满三个月，尚未关联合同。"
            : "项目「{$projectName}」创建已满三个月，尚未发生回款。";
    }

    private function auditRead(Request $request, ProjectNotification $notification): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'notification.read',
            'subject_type' => 'project_notification',
            'subject_id' => (string) $notification->id,
            'payload' => [
                'project_id' => $notification->project_id,
                'type' => $notification->type,
            ],
        ]);
    }
}
