<?php

namespace App\Actions;

use App\Models\ObjectRecord;
use App\Support\CollectionProgress;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SyncProjectFinance
{
    public function __construct(private CollectionProgress $collectionProgress) {}

    public function lockProjects(array $projectIds): Collection
    {
        $ids = collect($projectIds)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->sort()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return ObjectRecord::query()
            ->whereIn('id', $ids->all())
            ->whereRelation('businessObject', 'key', 'project')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    public function lockedProjectOrFail(?string $projectId, ?Collection $lockedProjects = null): ObjectRecord
    {
        if (! $projectId) {
            throw ValidationException::withMessages([
                'payload.project_id' => '项目名称必须选择有效的项目。',
            ]);
        }

        $project = $lockedProjects?->get($projectId)
            ?? $this->lockProjects([$projectId])->get($projectId);

        if (! $project) {
            throw ValidationException::withMessages([
                'payload.project_id' => '项目名称必须选择有效的项目。',
            ]);
        }

        return $project;
    }

    public function guardCustomerMatchesProject(ObjectRecord $project, ?string $customerId): void
    {
        if (($project->payload['customer_id'] ?? null) !== $customerId) {
            throw ValidationException::withMessages([
                'payload.customer_id' => '客户必须与所选项目的客户一致。',
            ]);
        }
    }

    public function fillContractProjectDefaults(
        array $payload,
        array $existingPayload = [],
        ?ObjectRecord $project = null,
    ): array {
        $project ??= $this->findProject($payload['project_id'] ?? null);
        if (! $project) {
            return $payload;
        }

        $customerId = $payload['customer_id'] ?? null;
        $projectChanged = ($existingPayload['project_id'] ?? null) !== null
            && ($existingPayload['project_id'] ?? null) !== ($payload['project_id'] ?? null);
        $keptPreviousCustomer = $projectChanged
            && $customerId === ($existingPayload['customer_id'] ?? null);

        if (trim((string) $customerId) === '' || $keptPreviousCustomer) {
            $payload['customer_id'] = $project->payload['customer_id'] ?? null;
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function withCalculatedPaymentProgress(array $payload): array
    {
        $payload['payment_progress'] = $this->collectionProgress->percentage(
            $payload['occurred_amount'] ?? null,
            $payload['paid_amount'] ?? null,
        );

        return $payload;
    }

    private function findProject(?string $projectId): ?ObjectRecord
    {
        if (! $projectId) {
            return null;
        }

        return ObjectRecord::query()
            ->whereKey($projectId)
            ->whereRelation('businessObject', 'key', 'project')
            ->first();
    }
}
