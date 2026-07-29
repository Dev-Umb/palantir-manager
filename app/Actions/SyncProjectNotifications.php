<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ProjectNotification;
use App\Models\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncProjectNotifications
{
    /** @return array{created: int, reactivated: int, resolved: int} */
    public function handle(): array
    {
        return DB::transaction(function (): array {
            $projectObject = BusinessObject::where('key', 'project')->lockForUpdate()->first();
            if (! $projectObject) {
                return $this->emptySummary();
            }

            return $this->sync($projectObject);
        });
    }

    /** @return array{created: int, reactivated: int, resolved: int} */
    public function handleProjects(array $projectIds): array
    {
        $projectIds = collect($projectIds)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
        if (! $projectIds) {
            return $this->emptySummary();
        }

        return DB::transaction(function () use ($projectIds): array {
            $projectObject = BusinessObject::where('key', 'project')->first();
            if (! $projectObject) {
                return $this->emptySummary();
            }

            return $this->sync($projectObject, $projectIds);
        });
    }

    /** @return array{created: int, reactivated: int, resolved: int} */
    private function sync(BusinessObject $projectObject, ?array $projectIds = null): array
    {
        $now = now();
        $roleRecipients = Role::query()
            ->whereIn('name', ['admin', 'business', 'finance'])
            ->with('users:id')
            ->get()
            ->mapWithKeys(fn (Role $role) => [$role->name => $role->users->pluck('id')->all()]);

        $admins = $roleRecipients->get('admin', []);
        $finances = $roleRecipients->get('finance', []);
        $eligibleCreators = array_flip(array_unique([
            ...$roleRecipients->get('business', []),
            ...$admins,
        ]));

        /** @var array<string, array{project_id: string, type: string, user_id: int}> $desired */
        $desired = [];
        $projectQuery = $projectObject->records()->orderBy('id')->lockForUpdate();
        if ($projectIds !== null) {
            $projectQuery->whereIn('id', $projectIds);
        }

        foreach ($projectQuery->get() as $project) {
            if (! $this->isDue($project->created_at, $now)) {
                continue;
            }

            $creator = $project->created_by && isset($eligibleCreators[$project->created_by])
                ? [$project->created_by]
                : [];
            $payload = $project->payload ?? [];

            if (trim((string) ($payload['related_contract_no'] ?? '')) === '') {
                $this->addRecipients($desired, $project->id, ProjectNotification::TYPE_CONTRACT, [
                    ...$creator,
                    ...$admins,
                ]);
            }

            $paidAmount = is_numeric($payload['paid_amount'] ?? null)
                ? (float) $payload['paid_amount']
                : 0.0;
            if ($paidAmount <= 0) {
                $this->addRecipients($desired, $project->id, ProjectNotification::TYPE_PAYMENT, [
                    ...$creator,
                    ...$finances,
                    ...$admins,
                ]);
            }
        }

        $summary = $this->emptySummary();
        $existingQuery = ProjectNotification::query()->orderBy('id')->lockForUpdate();
        if ($projectIds !== null) {
            $existingQuery->whereIn('project_id', $projectIds);
        }
        $existing = $existingQuery->get()->keyBy(
            fn (ProjectNotification $notification) => $this->key(
                $notification->project_id,
                $notification->type,
                $notification->user_id,
            ),
        );

        foreach ($desired as $key => $attributes) {
            /** @var ProjectNotification|null $notification */
            $notification = $existing->get($key);
            if (! $notification) {
                $notification = ProjectNotification::create([
                    ...$attributes,
                    'status' => ProjectNotification::STATUS_ACTIVE,
                    'triggered_at' => $now,
                    'occurrences' => 1,
                ]);
                $summary['created']++;
                $this->audit('notification.created', $notification);

                continue;
            }

            if ($notification->status === ProjectNotification::STATUS_RESOLVED) {
                $notification->update([
                    'status' => ProjectNotification::STATUS_ACTIVE,
                    'read_at' => null,
                    'resolved_at' => null,
                    'triggered_at' => $now,
                    'occurrences' => $notification->occurrences + 1,
                ]);
                $summary['reactivated']++;
                $this->audit('notification.reactivated', $notification);
            }
        }

        foreach ($existing as $key => $notification) {
            if (isset($desired[$key]) || $notification->status !== ProjectNotification::STATUS_ACTIVE) {
                continue;
            }

            $notification->update([
                'status' => ProjectNotification::STATUS_RESOLVED,
                'resolved_at' => $now,
            ]);
            $summary['resolved']++;
            $this->audit('notification.resolved', $notification);
        }

        return $summary;
    }

    /** @return array{created: int, reactivated: int, resolved: int} */
    private function emptySummary(): array
    {
        return ['created' => 0, 'reactivated' => 0, 'resolved' => 0];
    }

    private function isDue(Carbon $createdAt, Carbon $now): bool
    {
        return $createdAt->copy()->addMonthsNoOverflow(3)->lte($now);
    }

    /**
     * @param  array<string, array{project_id: string, type: string, user_id: int}>  $desired
     * @param  array<int, int>  $recipientIds
     */
    private function addRecipients(array &$desired, string $projectId, string $type, array $recipientIds): void
    {
        foreach (array_unique($recipientIds) as $userId) {
            $desired[$this->key($projectId, $type, $userId)] = [
                'project_id' => $projectId,
                'type' => $type,
                'user_id' => $userId,
            ];
        }
    }

    private function key(string $projectId, string $type, int $userId): string
    {
        return $projectId.'|'.$type.'|'.$userId;
    }

    private function audit(string $action, ProjectNotification $notification): void
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
