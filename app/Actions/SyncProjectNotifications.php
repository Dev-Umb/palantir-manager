<?php

namespace App\Actions;

use App\Integrations\Feishu\FeishuNotificationDispatcher;
use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\ProjectNotification;
use App\Models\ProjectReminderState;
use App\Models\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncProjectNotifications
{
    public function __construct(private FeishuNotificationDispatcher $feishu) {}

    /** @return array{created: int, reactivated: int, resolved: int, triggered: int} */
    public function handle(): array
    {
        return DB::transaction(function (): array {
            $projectObject = BusinessObject::query()->where('key', 'project')->lockForUpdate()->first();
            if (! $projectObject) {
                return $this->emptySummary();
            }

            return $this->sync($projectObject);
        });
    }

    /** @param array<int, mixed> $projectIds
     * @return array{created: int, reactivated: int, resolved: int, triggered: int}
     */
    public function handleProjects(array $projectIds): array
    {
        $projectIds = collect($projectIds)
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($projectIds === []) {
            return $this->emptySummary();
        }

        return DB::transaction(function () use ($projectIds): array {
            $projectObject = BusinessObject::query()->where('key', 'project')->first();
            if (! $projectObject) {
                return $this->emptySummary();
            }

            return $this->sync($projectObject, $projectIds);
        });
    }

    /** @param array<int, string>|null $projectIds
     * @return array{created: int, reactivated: int, resolved: int, triggered: int}
     */
    private function sync(BusinessObject $projectObject, ?array $projectIds = null): array
    {
        $now = now();
        $recipients = Role::query()
            ->whereIn('name', ['admin', 'finance'])
            ->with('users:id')
            ->get()
            ->mapWithKeys(fn (Role $role) => [$role->name => $role->users->pluck('id')->all()]);
        $admins = $recipients->get('admin', []);
        $finances = $recipients->get('finance', []);
        $summary = $this->emptySummary();
        $projectQuery = $projectObject->records()->orderBy('id')->lockForUpdate();
        if ($projectIds !== null) {
            $projectQuery->whereIn('id', $projectIds);
        }

        foreach ($projectQuery->get() as $project) {
            $desired = $this->desiredReminders($project, $admins, $finances, $now);
            $desiredTypes = array_keys($desired);
            $this->resolveRemovedTypes($project, $desiredTypes, $now, $summary);

            foreach ($desired as $type => $definition) {
                $this->resolveIfAnchorChanged($project, $type, $definition['anchor'], $now, $summary);
                $state = $this->stateFor($project, $type, $definition['anchor'], $definition['first'], $now);
                if ($state->next_due_at->gt($now)) {
                    continue;
                }

                $state->update([
                    'last_triggered_at' => $now,
                    'next_due_at' => $this->nextDue($now, $definition['repeat']),
                    'occurrences' => $state->occurrences + 1,
                ]);
                $summary['triggered']++;

                if ($type === ProjectNotification::TYPE_PAYMENT) {
                    $payload = $project->payload ?? [];
                    $payload['collection_count'] = (int) ($payload['collection_count'] ?? 0) + 1;
                    $project->update(['payload' => $payload]);
                }

                $this->triggerRecipients($project, $type, $definition['recipients'], $now, $summary);
                AuditLog::create([
                    'user_id' => null,
                    'action' => 'project_reminder.triggered',
                    'subject_type' => 'project',
                    'subject_id' => $project->id,
                    'payload' => [
                        'type' => $type,
                        'occurrences' => $state->occurrences,
                        'next_due_at' => $state->next_due_at->toISOString(),
                    ],
                ]);
            }
        }

        return $summary;
    }

    /**
     * @param  array<int, int>  $admins
     * @param  array<int, int>  $finances
     * @return array<string, array{anchor: Carbon, first: string, repeat: string, recipients: array<int, int>}>
     */
    private function desiredReminders(ObjectRecord $project, array $admins, array $finances, Carbon $now): array
    {
        $payload = $project->payload ?? [];
        $status = $payload['overall_status'] ?? '投标中';
        if ($status === '已完成') {
            return [];
        }

        $businessOwnerId = filter_var($payload['business_owner_user_id'] ?? null, FILTER_VALIDATE_INT);
        $businessRecipients = $businessOwnerId ? [(int) $businessOwnerId] : [];
        $lifecycleRecipients = array_values(array_unique([...$businessRecipients, ...$admins]));
        $paymentRecipients = array_values(array_unique([...$businessRecipients, ...$finances, ...$admins]));
        $statusAnchor = $this->date($payload['overall_status_changed_at'] ?? null, $project->updated_at ?? $now);
        $processingAnchor = $this->date($payload['processing_letter_at'] ?? null, $statusAnchor);
        $paymentAnchor = $this->date($payload['payment_reminder_anchor_at'] ?? null, $processingAnchor);
        $definitions = [];

        if ($status === '投标中') {
            $definitions[ProjectNotification::TYPE_BID] = [
                'anchor' => $statusAnchor,
                'first' => '15_days',
                'repeat' => '15_days',
                'recipients' => $lifecycleRecipients,
            ];
        }
        if ($status === '已中标' && ($payload['contract_status'] ?? '未签署') === '未签署') {
            $definitions[ProjectNotification::TYPE_PROCESSING_LETTER] = [
                'anchor' => $statusAnchor,
                'first' => '15_days',
                'repeat' => '15_days',
                'recipients' => $lifecycleRecipients,
            ];
        }
        if (in_array($payload['contract_status'] ?? null, ['已有加工函', '部分签署'], true)) {
            $definitions[ProjectNotification::TYPE_SIGNATURE] = [
                'anchor' => $processingAnchor,
                'first' => '3_months',
                'repeat' => '15_days',
                'recipients' => $lifecycleRecipients,
            ];
        }
        if (in_array($status, ['已拿到加工函', '合同签署'], true)
            && ($payload['payment_status'] ?? '未回款') !== '已回款') {
            $definitions[ProjectNotification::TYPE_PAYMENT] = [
                'anchor' => $paymentAnchor,
                'first' => '1_month',
                'repeat' => '15_days',
                'recipients' => $paymentRecipients,
            ];
        }

        return collect($definitions)
            ->filter(fn (array $definition): bool => $definition['recipients'] !== [])
            ->all();
    }

    private function stateFor(
        ObjectRecord $project,
        string $type,
        Carbon $anchor,
        string $firstInterval,
        Carbon $now,
    ): ProjectReminderState {
        $state = ProjectReminderState::query()
            ->where('project_id', $project->id)
            ->where('type', $type)
            ->lockForUpdate()
            ->first();
        $firstDue = $this->nextDue($anchor, $firstInterval);

        if (! $state) {
            return ProjectReminderState::create([
                'project_id' => $project->id,
                'type' => $type,
                'anchor_at' => $anchor,
                'next_due_at' => $firstDue,
                'occurrences' => 0,
            ]);
        }

        if (! $state->anchor_at->equalTo($anchor)) {
            $state->update([
                'anchor_at' => $anchor,
                'next_due_at' => $firstDue,
                'last_triggered_at' => null,
            ]);
        }

        return $state->fresh();
    }

    /** @param array{created: int, reactivated: int, resolved: int, triggered: int} $summary */
    private function resolveIfAnchorChanged(
        ObjectRecord $project,
        string $type,
        Carbon $anchor,
        Carbon $now,
        array &$summary,
    ): void {
        $state = ProjectReminderState::query()
            ->where('project_id', $project->id)
            ->where('type', $type)
            ->first();
        if (! $state || $state->anchor_at->equalTo($anchor)) {
            return;
        }

        ProjectNotification::query()
            ->where('project_id', $project->id)
            ->where('type', $type)
            ->active()
            ->lockForUpdate()
            ->get()
            ->each(function (ProjectNotification $notification) use ($now, &$summary): void {
                $notification->update([
                    'status' => ProjectNotification::STATUS_RESOLVED,
                    'resolved_at' => $now,
                ]);
                $summary['resolved']++;
                $this->auditNotification('notification.resolved_after_anchor_reset', $notification);
            });
    }

    /** @param array<int, string> $desiredTypes
     * @param  array{created: int, reactivated: int, resolved: int, triggered: int}  $summary
     */
    private function resolveRemovedTypes(ObjectRecord $project, array $desiredTypes, Carbon $now, array &$summary): void
    {
        ProjectReminderState::query()
            ->where('project_id', $project->id)
            ->when($desiredTypes !== [], fn ($query) => $query->whereNotIn('type', $desiredTypes))
            ->delete();

        ProjectNotification::query()
            ->where('project_id', $project->id)
            ->active()
            ->when($desiredTypes !== [], fn ($query) => $query->whereNotIn('type', $desiredTypes))
            ->lockForUpdate()
            ->get()
            ->each(function (ProjectNotification $notification) use ($now, &$summary): void {
                $notification->update([
                    'status' => ProjectNotification::STATUS_RESOLVED,
                    'resolved_at' => $now,
                ]);
                $summary['resolved']++;
                $this->auditNotification('notification.resolved', $notification);
            });
    }

    /** @param array<int, int> $recipientIds
     * @param  array{created: int, reactivated: int, resolved: int, triggered: int}  $summary
     */
    private function triggerRecipients(
        ObjectRecord $project,
        string $type,
        array $recipientIds,
        Carbon $now,
        array &$summary,
    ): void {
        $recipientIds = array_values(array_unique($recipientIds));
        ProjectNotification::query()
            ->where('project_id', $project->id)
            ->where('type', $type)
            ->whereNotIn('user_id', $recipientIds)
            ->active()
            ->lockForUpdate()
            ->get()
            ->each(function (ProjectNotification $notification) use ($now, &$summary): void {
                $notification->update([
                    'status' => ProjectNotification::STATUS_RESOLVED,
                    'resolved_at' => $now,
                ]);
                $summary['resolved']++;
                $this->auditNotification('notification.resolved', $notification);
            });

        foreach ($recipientIds as $userId) {
            $notification = ProjectNotification::query()
                ->where('project_id', $project->id)
                ->where('type', $type)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
            if (! $notification) {
                $notification = ProjectNotification::create([
                    'project_id' => $project->id,
                    'type' => $type,
                    'user_id' => $userId,
                    'status' => ProjectNotification::STATUS_ACTIVE,
                    'triggered_at' => $now,
                    'occurrences' => 1,
                ]);
                $summary['created']++;
                $this->auditNotification('notification.created', $notification);
                $this->feishu->dispatch($notification);

                continue;
            }

            $reactivated = $notification->status === ProjectNotification::STATUS_RESOLVED;
            $notification->update([
                'status' => ProjectNotification::STATUS_ACTIVE,
                'read_at' => null,
                'resolved_at' => null,
                'triggered_at' => $now,
                'occurrences' => $notification->occurrences + 1,
            ]);
            if ($reactivated) {
                $summary['reactivated']++;
            }
            $this->auditNotification($reactivated ? 'notification.reactivated' : 'notification.repeated', $notification);
            $this->feishu->dispatch($notification);
        }
    }

    private function nextDue(Carbon $anchor, string $interval): Carbon
    {
        return match ($interval) {
            '15_days' => $anchor->copy()->addDays(15),
            '1_month' => $anchor->copy()->addMonthNoOverflow(),
            '3_months' => $anchor->copy()->addMonthsNoOverflow(3),
            default => throw new \LogicException("Unsupported reminder interval [{$interval}]."),
        };
    }

    private function date(mixed $value, Carbon $fallback): Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : $fallback->copy();
    }

    /** @return array{created: int, reactivated: int, resolved: int, triggered: int} */
    private function emptySummary(): array
    {
        return ['created' => 0, 'reactivated' => 0, 'resolved' => 0, 'triggered' => 0];
    }

    private function auditNotification(string $action, ProjectNotification $notification): void
    {
        AuditLog::create([
            'user_id' => null,
            'action' => $action,
            'subject_type' => 'project_notification',
            'subject_id' => (string) $notification->id,
            'payload' => [
                'project_id' => $notification->project_id,
                'type' => $notification->type,
                'recipient_id' => $notification->user_id,
                'occurrences' => $notification->occurrences,
            ],
        ]);
    }
}
