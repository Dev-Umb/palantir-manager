<?php

namespace App\Actions;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;

class SyncProjectInvoiceAmount
{
    public function handle(?string $projectId): void
    {
        if (! $projectId) {
            return;
        }

        $project = ObjectRecord::whereKey($projectId)
            ->whereRelation('businessObject', 'key', 'project')
            ->first();
        $invoice = BusinessObject::where('key', 'invoice')->first();

        if (! $project || ! $invoice) {
            return;
        }

        $amount = $invoice->records()
            ->where('payload->project_id', $project->id)
            ->get()
            ->reject(fn (ObjectRecord $record) => ($record->payload['status'] ?? null) === '已作废')
            ->sum(fn (ObjectRecord $record) => (float) ($record->payload['amount'] ?? 0));

        $payload = $project->payload ?? [];
        $payload['invoiced_amount'] = $amount;
        $payload['uninvoiced_amount'] = max((float) ($payload['contract_amount'] ?? 0) - $amount, 0);

        $project->update(['payload' => $payload]);
    }
}
