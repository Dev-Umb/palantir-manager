<?php

namespace App\Http\Controllers;

use App\Actions\SyncProjectNotifications;
use App\Actions\SyncTenderNotifications;
use App\Models\AuditLog;
use App\Models\ProjectNotification;
use App\Models\TenderNotification;
use App\Support\ProjectVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function __construct(
        private SyncProjectNotifications $sync,
        private SyncTenderNotifications $tenderSync,
        private ProjectVisibility $projectVisibility,
    ) {}

    public function index(Request $request): Response
    {
        $this->sync->handle();
        $this->tenderSync->handle();

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
                    'type_label' => $this->typeLabel($notification->type),
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
        $canViewTender = $user->canDo('object.tender.view');
        $tenderNotifications = TenderNotification::query()
            ->where('user_id', $user->id)
            ->with(['tender', 'project'])
            ->orderBy('status')
            ->latest('triggered_at')
            ->paginate(20, ['*'], 'tender_page')
            ->withQueryString()
            ->through(function (TenderNotification $notification) use ($canViewTender, $user): array {
                $tender = $notification->tender;
                $payload = $tender?->payload ?? [];
                $project = $notification->project;
                $canViewProject = $project && $this->projectVisibility->allowsProject($user, $project);

                return [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'type_label' => $this->tenderTypeLabel($notification),
                    'message' => $this->tenderMessage($notification),
                    'status' => $notification->status,
                    'read_at' => $notification->read_at?->toISOString(),
                    'resolved_at' => $notification->resolved_at?->toISOString(),
                    'triggered_at' => $notification->triggered_at?->toISOString(),
                    'deadline_at' => $notification->deadline_at?->toISOString(),
                    'occurrences' => $notification->occurrences,
                    'tender' => $tender ? [
                        'id' => $tender->id,
                        'code' => $tender->code,
                        'name' => $payload['name'] ?? $tender->title ?? $tender->code,
                    ] : null,
                    'tender_url' => $canViewTender && $tender
                        ? "/objects/tender?record={$tender->id}&mode=detail"
                        : null,
                    'project' => $project ? [
                        'id' => $project->id,
                        'code' => $project->code,
                        'name' => $project->payload['name'] ?? $project->title ?? $project->code,
                    ] : null,
                    'project_url' => $canViewProject
                        ? "/objects/project?record={$project->id}&mode=detail"
                        : null,
                    'read_url' => route('tender-notifications.read', $notification, false),
                ];
            });

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'tenderNotifications' => $tenderNotifications,
            'unreadCount' => ProjectNotification::query()
                ->where('user_id', $user->id)
                ->active()
                ->whereNull('read_at')
                ->count() + TenderNotification::query()
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
        $projectCount = ProjectNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => $now, 'updated_at' => $now]);
        $tenderCount = TenderNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => $now, 'updated_at' => $now]);
        $count = $projectCount + $tenderCount;

        if ($count > 0) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'notification.read_all',
                'subject_type' => 'project_notification',
                'subject_id' => null,
                'payload' => [
                    'count' => $count,
                    'project_count' => $projectCount,
                    'tender_count' => $tenderCount,
                ],
            ]);
        }

        return back();
    }

    public function markTenderRead(Request $request, TenderNotification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'tender_notification.read',
                'subject_type' => 'tender_notification',
                'subject_id' => (string) $notification->id,
                'payload' => [
                    'tender_id' => $notification->tender_id,
                    'type' => $notification->type,
                ],
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

        return match ($notification->type) {
            ProjectNotification::TYPE_BID => "项目「{$projectName}」投标状态已停留 15 天，请跟进中标结果。",
            ProjectNotification::TYPE_PROCESSING_LETTER => "项目「{$projectName}」中标后已满 15 天，请跟进加工函。",
            ProjectNotification::TYPE_SIGNATURE => "项目「{$projectName}」取得加工函后合同仍未全部签署，请继续催签。",
            ProjectNotification::TYPE_PAYMENT => "项目「{$projectName}」回款仍未完成，请跟进本期催款。",
            default => "项目「{$projectName}」存在待处理提醒。",
        };
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            ProjectNotification::TYPE_BID => '投标提醒',
            ProjectNotification::TYPE_PROCESSING_LETTER => '加工函提醒',
            ProjectNotification::TYPE_SIGNATURE => '合同签署提醒',
            ProjectNotification::TYPE_PAYMENT => '回款提醒',
            default => '业务提醒',
        };
    }

    private function tenderTypeLabel(TenderNotification $notification): string
    {
        if ($notification->type === TenderNotification::TYPE_CONVERSION) {
            return '中标流转';
        }

        $deadline = match ($notification->deadline_type) {
            'register' => '报名截止',
            'purchase' => '购买标书截止',
            default => '投标截止',
        };
        $stage = match ($notification->stage) {
            'd3' => '72 小时内',
            'd1' => '24 小时内',
            default => '今日',
        };

        return "{$deadline}（{$stage}）";
    }

    private function tenderMessage(TenderNotification $notification): string
    {
        $tenderName = $notification->tender?->payload['name']
            ?? $notification->tender?->title
            ?? $notification->tender?->code
            ?? '已删除招投标记录';

        if ($notification->type === TenderNotification::TYPE_CONVERSION) {
            $projectCode = $notification->project?->code ?? '项目';

            return "招投标「{$tenderName}」已中标并流转至 {$projectCode}。";
        }

        $deadline = match ($notification->deadline_type) {
            'register' => '报名',
            'purchase' => '购买标书',
            default => '投标',
        };

        return "招投标「{$tenderName}」即将到达{$deadline}截止时间。";
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
