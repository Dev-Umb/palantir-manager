<?php

namespace App\Actions;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;

class SyncProjectContractAmount
{
    public function handle(?string $projectId): void
    {
        if (! $projectId) {
            return;
        }

        $project = ObjectRecord::whereKey($projectId)
            ->whereRelation('businessObject', 'key', 'project')
            ->first();

        $contract = BusinessObject::where('key', 'contract')->first();
        if (! $project || ! $contract) {
            return;
        }

        $contracts = $contract->records()
            ->where('payload->project_id', $project->id)
            ->get();

        $payload = $project->payload ?? [];
        $payload['contract_amount'] = $contracts->sum(fn (ObjectRecord $record) => (float) ($record->payload['amount'] ?? 0));
        $payload['related_contract_no'] = $contracts->pluck('code')->implode('、');
        $project->update(['payload' => $payload]);
    }
}
