<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\ObjectRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcknowledgeWorkflowTask
{
    public function visibleTo(ObjectRecord $record, ?User $user): bool
    {
        if (! $user
            || ! $record->workflow_key
            || $record->workflow_seen_at !== null) {
            return false;
        }

        $roleNames = $user->roles->pluck('name');
        if ($roleNames->contains('admin')) {
            return true;
        }

        return $roleNames->intersect($record->workflow_target_roles ?? [])->isNotEmpty();
    }

    public function handle(ObjectRecord $record, User $user): bool
    {
        return DB::transaction(function () use ($record, $user): bool {
            $locked = ObjectRecord::query()
                ->with('businessObject')
                ->lockForUpdate()
                ->findOrFail($record->id);
            if (! $this->visibleTo($locked, $user)) {
                return false;
            }

            $locked->update([
                'workflow_seen_at' => now(),
                'workflow_seen_by' => $user->id,
            ]);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'object.workflow.acknowledge',
                'subject_type' => $locked->businessObject->key,
                'subject_id' => $locked->id,
                'payload' => ['workflow_key' => $locked->workflow_key],
            ]);

            return true;
        });
    }
}
