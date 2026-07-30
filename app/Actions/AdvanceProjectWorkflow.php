<?php

namespace App\Actions;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;

class AdvanceProjectWorkflow
{
    public function __construct(private SyncProjectFinance $projectFinance) {}

    public function handle(
        ObjectRecord $record,
        array $oldPayload,
        ?User $user,
        CreateObjectRecord $writer,
    ): void {
        $record->loadMissing('businessObject');
        $payload = $record->payload ?? [];

        if ($record->businessObject->key === 'contract') {
            $becameReceived = ($oldPayload['status'] ?? null) !== '已收到'
                && ($payload['status'] ?? null) === '已收到';
            $movedWhileReceived = ($payload['status'] ?? null) === '已收到'
                && isset($oldPayload['project_id'])
                && ($oldPayload['project_id'] ?? null) !== ($payload['project_id'] ?? null);
            if ($becameReceived || $movedWhileReceived) {
                $this->activateContract($record, $user, $writer);
            }

            $this->syncContractFinance($payload['project_id'] ?? null, $user);

            return;
        }

        if ($record->businessObject->key === 'drawing'
            && ($oldPayload['design_status'] ?? null) !== '已下放'
            && ($payload['design_status'] ?? null) === '已下放') {
            $this->releaseDrawing($record, $user, $writer);

            return;
        }

        if ($record->businessObject->key === 'work_order'
            && ($oldPayload['status'] ?? null) !== '已完成'
            && ($payload['status'] ?? null) === '已完成') {
            $this->completeWorkOrder($record, $user, $writer);

            return;
        }

        if ($record->businessObject->key === 'shipment'
            && trim((string) ($oldPayload['ship_date'] ?? '')) === ''
            && trim((string) ($payload['ship_date'] ?? '')) !== '') {
            $project = $this->project($payload['project_id'] ?? null);
            if ($project) {
                $this->advanceProjectStage($project, '发货签收');
            }
        }
    }

    private function activateContract(
        ObjectRecord $contract,
        ?User $user,
        CreateObjectRecord $writer,
    ): void {
        $project = $this->project($contract->payload['project_id'] ?? null);
        if (! $project) {
            return;
        }

        $drawingKey = "project:{$project->id}:initial-drawing";
        if (! ObjectRecord::where('workflow_key', $drawingKey)->exists()) {
            $writer->handle(
                $this->object('drawing'),
                [
                    'project_id' => $project->id,
                    'name' => "{$project->title}-技术图纸",
                    'design_status' => '草稿',
                ],
                $user,
                'object.workflow.create',
                $drawingKey,
                ['engineering'],
            );
        }

        $receivable = ObjectRecord::query()
            ->whereRelation('businessObject', 'key', 'receivable')
            ->where('payload->project_id', $project->id)
            ->first();
        if (! $receivable) {
            $writer->handle(
                $this->object('receivable'),
                [
                    'customer_id' => $project->payload['customer_id'] ?? null,
                    'project_id' => $project->id,
                    'pay_status' => '未回款',
                    'signed_weight' => 0,
                    'occurred_amount' => 0,
                    'paid_amount' => 0,
                    'reconciled_amount' => 0,
                    'invoiced_amount' => 0,
                    'contract_amount' => $this->receivedContractAmount($project->id),
                ],
                $user,
                'object.workflow.create',
                "project:{$project->id}:finance",
                ['finance'],
            );
        }

        $this->advanceProjectStage($project, '技术确认');
    }

    private function releaseDrawing(
        ObjectRecord $drawing,
        ?User $user,
        CreateObjectRecord $writer,
    ): void {
        $project = $this->project($drawing->payload['project_id'] ?? null);
        if (! $project) {
            return;
        }

        $workflowKey = "drawing:{$drawing->id}:work-order";
        if (! ObjectRecord::where('workflow_key', $workflowKey)->exists()) {
            $writer->handle(
                $this->object('work_order'),
                [
                    'drawing_id' => $drawing->id,
                    'status' => '未开始',
                    'release_status' => '未下放',
                ],
                $user,
                'object.workflow.create',
                $workflowKey,
                ['production_manager', 'production'],
            );
        }

        $this->advanceProjectStage($project, '生产加工');
    }

    private function completeWorkOrder(
        ObjectRecord $workOrder,
        ?User $user,
        CreateObjectRecord $writer,
    ): void {
        $project = $this->project($workOrder->payload['project_id'] ?? null);
        if (! $project) {
            return;
        }

        $workflowKey = "work-order:{$workOrder->id}:shipment";
        if (! ObjectRecord::where('workflow_key', $workflowKey)->exists()) {
            $writer->handle(
                $this->object('shipment'),
                [
                    'project_id' => $project->id,
                    'product_name' => (string) ($workOrder->payload['drawing_name'] ?? $workOrder->title),
                ],
                $user,
                'object.workflow.create',
                $workflowKey,
                ['production_manager', 'production'],
            );
        }

        $this->advanceProjectStage($project, '成品发货');
    }

    public function syncContractFinance(?string $projectId, ?User $user): void
    {
        $project = $this->project($projectId);
        if (! $project) {
            return;
        }

        $receivable = ObjectRecord::query()
            ->whereRelation('businessObject', 'key', 'receivable')
            ->where('payload->project_id', $project->id)
            ->first();
        if (! $receivable) {
            return;
        }

        $payload = $receivable->payload ?? [];
        $amount = $this->receivedContractAmount($project->id);
        if ((float) ($payload['contract_amount'] ?? 0) === $amount) {
            return;
        }

        $payload['contract_amount'] = $amount;
        $payload = $this->projectFinance->normalizePayload($payload, $project);
        $receivable->update(['payload' => $payload]);
        $this->projectFinance->handleLocked($project, $user);
    }

    private function receivedContractAmount(string $projectId): float
    {
        return round($this->object('contract')->records()
            ->where('payload->project_id', $projectId)
            ->where('payload->status', '已收到')
            ->get()
            ->sum(fn (ObjectRecord $contract): float => (float) ($contract->payload['amount'] ?? 0)), 2);
    }

    private function advanceProjectStage(ObjectRecord $project, string $target): void
    {
        $project->refresh();
        $rank = collect([
            '合同录入',
            '技术确认',
            '正在对接',
            '设计出图',
            '采购执行',
            '生产加工',
            '成品发货',
            '发货签收',
            '对账回款',
            '项目完成',
        ])->flip();
        $current = (string) ($project->payload['stage'] ?? '');
        if ($current === '异常'
            || ($rank->has($current) && $rank->get($current) >= $rank->get($target))) {
            return;
        }

        $project->update(['payload' => [...($project->payload ?? []), 'stage' => $target]]);
    }

    private function project(?string $projectId): ?ObjectRecord
    {
        if (! $projectId) {
            return null;
        }

        return ObjectRecord::query()
            ->whereKey($projectId)
            ->whereRelation('businessObject', 'key', 'project')
            ->lockForUpdate()
            ->first();
    }

    private function object(string $key): BusinessObject
    {
        return BusinessObject::query()->where('key', $key)->lockForUpdate()->firstOrFail();
    }
}
