<?php

namespace App\Actions;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use Illuminate\Support\Facades\DB;

class SyncProjectContractAmount
{
    public function handle(?string $projectId): void
    {
        if (! $projectId) {
            return;
        }

        DB::transaction(fn () => $this->sync($projectId));
    }

    private function sync(string $projectId): void
    {
        $project = ObjectRecord::whereKey($projectId)
            ->whereRelation('businessObject', 'key', 'project')
            ->lockForUpdate()
            ->first();

        $contract = BusinessObject::where('key', 'contract')->first();
        if (! $project || ! $contract) {
            return;
        }

        $contracts = $contract->records()
            ->where('payload->project_id', $project->id)
            ->get();

        $payload = $project->payload ?? [];
        $payload['related_contract_no'] = $contracts->pluck('code')->implode('、');
        $payload['contract_qty'] = round($contracts->sum(
            fn (ObjectRecord $contract): float => (float) ($contract->payload['contract_qty'] ?? 0),
        ), 4);
        $project->update(['payload' => $payload]);
    }
}
