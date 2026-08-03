<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\TenderNotification;
use App\Models\User;
use App\Support\ObjectRelations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertTenderToProject
{
    public function __construct(
        private CreateObjectRecord $records,
        private ObjectRelations $relations,
    ) {}

    public function handle(ObjectRecord $tender, User $assignee, User $actor): ObjectRecord
    {
        if (! $actor->canDo('object.tender.update')) {
            throw ValidationException::withMessages([
                'tender' => '当前用户无权流转招投标记录。',
            ]);
        }
        if (! $assignee->roles()->where('name', 'business')->exists()) {
            throw ValidationException::withMessages([
                'assignee_user_id' => '接手人必须具有业务角色。',
            ]);
        }

        return DB::transaction(function () use ($tender, $assignee, $actor): ObjectRecord {
            $this->relations->lockReferenceGraph();
            $lockedTender = ObjectRecord::query()
                ->with('businessObject')
                ->lockForUpdate()
                ->findOrFail($tender->id);
            if ($lockedTender->businessObject?->key !== 'tender') {
                throw ValidationException::withMessages([
                    'tender' => '只能流转招投标记录。',
                ]);
            }

            $existingProjectId = $lockedTender->payload['converted_project_id'] ?? null;
            if (is_string($existingProjectId) && $existingProjectId !== '') {
                return ObjectRecord::query()
                    ->whereKey($existingProjectId)
                    ->whereRelation('businessObject', 'key', 'project')
                    ->firstOrFail();
            }

            $projectObject = BusinessObject::query()->where('key', 'project')->firstOrFail();
            $tenderPayload = $lockedTender->payload ?? [];
            $project = $this->records->handle(
                $projectObject,
                [
                    'name' => (string) ($tenderPayload['name'] ?? $lockedTender->title),
                    'customer_id' => $tenderPayload['customer_id'] ?? null,
                    'business_owner_user_id' => (string) $assignee->id,
                    'informed_business_user_ids' => [],
                    'overall_status' => '已中标',
                    'overall_status_changed_at' => now()->toISOString(),
                    'contract_status' => '未签署',
                    'collection_count' => 0,
                ],
                $actor,
                action: 'tender.project.create',
            );
            $project->update(['created_by' => $assignee->id]);

            $lockedTender->update([
                'payload' => [
                    ...$tenderPayload,
                    'status' => '已中标',
                    'converted_project_id' => $project->id,
                    'assignee_user_id' => (string) $assignee->id,
                ],
            ]);

            $admin = Role::query()->where('name', 'admin')->with('users:id')->first();
            $recipientIds = collect($admin?->users->pluck('id') ?? [])
                ->push($assignee->id)
                ->unique()
                ->values();
            foreach ($recipientIds as $userId) {
                TenderNotification::query()->firstOrCreate(
                    [
                        'tender_id' => $lockedTender->id,
                        'deadline_type' => 'conversion',
                        'stage' => 'converted',
                        'user_id' => $userId,
                    ],
                    [
                        'type' => TenderNotification::TYPE_CONVERSION,
                        'project_id' => $project->id,
                        'status' => TenderNotification::STATUS_ACTIVE,
                        'triggered_at' => now(),
                        'occurrences' => 1,
                    ],
                );
            }

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'tender.converted',
                'subject_type' => 'tender',
                'subject_id' => $lockedTender->id,
                'payload' => [
                    'project_id' => $project->id,
                    'assignee_user_id' => $assignee->id,
                ],
            ]);

            return $project;
        });
    }
}
