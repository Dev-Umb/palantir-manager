<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\ObjectRecord;
use App\Models\TenderNotification;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReassignTenderBusinessOwner
{
    public function handle(ObjectRecord $tender, array $nextPayload, User $actor): void
    {
        $currentPayload = $tender->payload ?? [];
        $currentAssigneeId = (string) ($currentPayload['assignee_user_id'] ?? '');
        $nextAssigneeId = (string) ($nextPayload['assignee_user_id'] ?? '');
        if ($currentAssigneeId === $nextAssigneeId) {
            return;
        }

        $projectId = $currentPayload['converted_project_id'] ?? null;
        if (($currentPayload['status'] ?? null) !== '已中标' || ! is_string($projectId) || $projectId === '') {
            throw ValidationException::withMessages([
                'payload.assignee_user_id' => '接手业务员只能在招投标中标并流转后修改。',
            ]);
        }

        $assignee = filter_var($nextAssigneeId, FILTER_VALIDATE_INT) !== false
            ? User::query()
                ->whereKey((int) $nextAssigneeId)
                ->whereHas('roles', fn ($query) => $query->where('name', 'business'))
                ->first()
            : null;
        if (! $assignee) {
            throw ValidationException::withMessages([
                'payload.assignee_user_id' => '接手业务员必须选择具有业务角色的账号。',
            ]);
        }

        $project = ObjectRecord::query()
            ->whereKey($projectId)
            ->whereRelation('businessObject', 'key', 'project')
            ->lockForUpdate()
            ->firstOrFail();
        $project->update([
            'payload' => [
                ...($project->payload ?? []),
                'business_owner_user_id' => (string) $assignee->id,
            ],
        ]);

        TenderNotification::query()->firstOrCreate(
            [
                'tender_id' => $tender->id,
                'deadline_type' => 'conversion',
                'stage' => 'converted',
                'user_id' => $assignee->id,
            ],
            [
                'type' => TenderNotification::TYPE_CONVERSION,
                'project_id' => $project->id,
                'status' => TenderNotification::STATUS_ACTIVE,
                'triggered_at' => now(),
                'occurrences' => 1,
            ],
        );

        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => 'tender.assignee.updated',
            'subject_type' => 'tender',
            'subject_id' => $tender->id,
            'payload' => [
                'project_id' => $project->id,
                'previous_assignee_user_id' => $currentAssigneeId,
                'assignee_user_id' => (string) $assignee->id,
            ],
        ]);
    }
}
