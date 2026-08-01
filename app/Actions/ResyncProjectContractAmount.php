<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResyncProjectContractAmount
{
    public function handle(ObjectRecord $project, User $actor): ObjectRecord
    {
        return DB::transaction(function () use ($project, $actor): ObjectRecord {
            $lockedProject = ObjectRecord::query()
                ->whereKey($project->id)
                ->whereRelation('businessObject', 'key', 'project')
                ->lockForUpdate()
                ->firstOrFail();
            $contractObject = BusinessObject::query()->where('key', 'contract')->firstOrFail();
            $amount = round($contractObject->records()
                ->where('payload->project_id', $lockedProject->id)
                ->sum('payload->amount'), 2);
            $payload = $lockedProject->payload ?? [];
            $before = $payload['contract_amount'] ?? null;
            $payload['contract_amount'] = $amount;
            $payload['contract_amount_source'] = 'contract_sync';
            $payload['contract_amount_synced_at'] = now()->toISOString();
            $payload['contract_amount_synced_by'] = $actor->id;
            $lockedProject->update(['payload' => $payload]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'project.contract_amount.resync',
                'subject_type' => 'project',
                'subject_id' => $lockedProject->id,
                'payload' => [
                    'before' => $before,
                    'after' => $amount,
                    'source' => 'contract_sync',
                ],
            ]);

            return $lockedProject->refresh();
        });
    }
}
