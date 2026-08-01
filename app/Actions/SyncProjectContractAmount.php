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
        unset($payload['related_contract_no']);
        $payload['contract_qty'] = round($contracts->sum(
            fn (ObjectRecord $contract): float => (float) ($contract->payload['contract_qty'] ?? 0),
        ), 4);

        $total = $contracts->count();
        $signed = $contracts->filter(
            fn (ObjectRecord $contract): bool => ($contract->payload['status'] ?? '未签署') === '已签署',
        )->count();
        $hasProcessingLetter = $contracts->contains(
            fn (ObjectRecord $contract): bool => in_array(
                $contract->payload['status'] ?? '未签署',
                ['已有加工函', '已签署'],
                true,
            ),
        );
        $payload['contract_status'] = match (true) {
            $total > 0 && $signed === $total => '已签署',
            $signed > 0 => '部分签署',
            $hasProcessingLetter => '已有加工函',
            default => '未签署',
        };

        if ($hasProcessingLetter && empty($payload['processing_letter_at'])) {
            $payload['processing_letter_at'] = now()->toISOString();
            $payload['payment_reminder_anchor_at'] = now()->toISOString();
        }

        if (($payload['overall_status'] ?? null) !== '已完成') {
            $nextOverallStatus = match ($payload['contract_status']) {
                '已签署' => '合同签署',
                '已有加工函', '部分签署' => '已拿到加工函',
                default => in_array($payload['overall_status'] ?? null, ['已拿到加工函', '合同签署'], true)
                    ? '已中标'
                    : ($payload['overall_status'] ?? '投标中'),
            };
            if (($payload['overall_status'] ?? null) !== $nextOverallStatus) {
                $payload['overall_status'] = $nextOverallStatus;
                $payload['overall_status_changed_at'] = now()->toISOString();
            }
        }

        $contractTotal = round($contracts->sum(
            fn (ObjectRecord $contract): float => (float) ($contract->payload['amount'] ?? 0),
        ), 2);
        if ($contracts->isNotEmpty()
            && ! is_numeric($payload['contract_amount'] ?? null)) {
            $payload['contract_amount'] = $contractTotal;
            $payload['contract_amount_source'] = 'contract_sync';
            $payload['contract_amount_synced_at'] = now()->toISOString();
        }

        $project->update(['payload' => $payload]);
    }
}
