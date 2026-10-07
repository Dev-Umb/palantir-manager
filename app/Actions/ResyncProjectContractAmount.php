<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            $contracts = $contractObject->records()
                ->where('payload->project_id', $lockedProject->id)
                ->get(['payload']);
            if ($contracts->contains(fn (ObjectRecord $contract): bool => ! is_numeric($contract->payload['amount'] ?? null))) {
                throw ValidationException::withMessages(['contract_amount' => '存在金额待确认的合同，请先补齐合同金额后再同步。']);
            }
            $amount = round($contracts->sum(fn (ObjectRecord $contract): float => (float) $contract->payload['amount']), 2);
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
