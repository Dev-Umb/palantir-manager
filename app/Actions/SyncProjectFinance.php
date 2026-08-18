<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\CollectionProgress;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

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

    public function guardUnique(
        ?string $projectId,
        ?string $exceptRecordId = null,
        ?ObjectRecord $lockedProject = null,
    ): ObjectRecord {
        $project = $lockedProject ?? $this->lockedProjectOrFail($projectId);

        $duplicate = ObjectRecord::query()
            ->whereRelation('businessObject', 'key', 'receivable')
            ->where('payload->project_id', $project->id)
            ->when($exceptRecordId, fn ($query) => $query->whereKeyNot($exceptRecordId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'payload.project_id' => '每个项目只能建立一条财务台账。',
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

    public function fillProjectDefaults(array $payload, ?ObjectRecord $project = null): array
    {
        $project ??= $this->findProject($payload['project_id'] ?? null);
        if (! $project) {
            return $payload;
        }

        $payload['project_no'] = (string) (($project->payload['project_no'] ?? '') ?: $project->code);
        if (trim((string) ($payload['customer_id'] ?? '')) === '') {
            $payload['customer_id'] = $project->payload['customer_id'] ?? null;
        }

        return $payload;
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

    public function normalizePayload(array $payload, ?ObjectRecord $project = null): array
    {
        $payload = $this->fillProjectDefaults($payload, $project);
        if (trim((string) ($payload['pay_status'] ?? '')) === '') {
            $payload['pay_status'] = $this->calculatedPaymentStatus($payload);
        }

        $occurred = $this->cents($payload, 'occurred_amount');
        $contract = $this->cents($payload, 'contract_amount');
        $paid = $this->cents($payload, 'paid_amount');
        $invoiced = $this->cents($payload, 'invoiced_amount');
        $base = $occurred > 0 ? $occurred : $contract;

        foreach (['contract_amount', 'occurred_amount', 'paid_amount', 'reconciled_amount', 'invoiced_amount'] as $key) {
            $payload[$key] = $this->decimal($this->cents($payload, $key));
        }
        $payload['unpaid_amount'] = $this->decimal(max($base - $paid, 0));
        $payload['uninvoiced_amount'] = $this->decimal(max($base - $invoiced, 0));
        $payload = $this->withCalculatedPaymentProgress($payload);

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

    public function paymentStatusWarning(array $payload): ?string
    {
        $selected = trim((string) ($payload['pay_status'] ?? ''));
        if ($selected === '') {
            return null;
        }

        $calculated = $this->calculatedPaymentStatus($payload);
        if ($selected === $calculated) {
            return null;
        }

        return "回款状态与金额不一致：按当前金额应为“{$calculated}”，已保留您选择的“{$selected}”。";
    }

    public function handle(?string $projectId, ?User $user = null): void
    {
        if (! $projectId) {
            return;
        }

        $project = $this->lockProjects([$projectId])->get($projectId);

        if (! $project) {
            return;
        }

        $this->handleLocked($project, $user);
    }

    public function handleLocked(ObjectRecord $project, ?User $user = null): void
    {
        $receivables = ObjectRecord::query()
            ->whereRelation('businessObject', 'key', 'receivable')
            ->where('payload->project_id', $project->id)
            ->limit(2)
            ->get();

        if ($receivables->count() > 1) {
            throw new LogicException('项目存在多条财务台账，无法确定唯一财务来源。');
        }

        $source = $receivables->first();
        $sourcePayload = $source ? $this->normalizePayload($source->payload ?? [], $project) : [];
        $occurred = $this->cents($sourcePayload, 'occurred_amount');
        $contract = $this->cents($sourcePayload, 'contract_amount');
        $paid = $this->cents($sourcePayload, 'paid_amount');
        $invoiced = $this->cents($sourcePayload, 'invoiced_amount');
        $base = $occurred > 0 ? $occurred : $contract;

        $mirror = [
            'signed_weight' => round((float) ($sourcePayload['signed_weight'] ?? 0), 2),
            'contract_amount' => $this->decimal($contract),
            'occurred_amount' => $this->decimal($occurred),
            'paid_amount' => $this->decimal($paid),
            'unpaid_amount' => $this->decimal(max($base - $paid, 0)),
            'reconciled_amount' => $this->decimal($this->cents($sourcePayload, 'reconciled_amount')),
            'invoiced_amount' => $this->decimal($invoiced),
            'uninvoiced_amount' => $this->decimal(max($base - $invoiced, 0)),
            'payment_progress' => $this->collectionProgress->percentage(
                $sourcePayload['occurred_amount'] ?? null,
                $sourcePayload['paid_amount'] ?? null,
            ),
            'payment_status' => $source
                ? (trim((string) ($sourcePayload['pay_status'] ?? '')) !== ''
                    ? $sourcePayload['pay_status']
                    : $this->calculatedPaymentStatus($sourcePayload))
                : '未回款',
            'last_payment_date' => $sourcePayload['last_payment_date'] ?? null,
        ];

        $project->update(['payload' => [...($project->payload ?? []), ...$mirror]]);

        AuditLog::create([
            'user_id' => $user?->id,
            'action' => 'object.finance.sync',
            'subject_type' => 'project',
            'subject_id' => $project->id,
            'payload' => [
                'receivable_id' => $source?->id,
                'mirror' => $mirror,
            ],
        ]);
    }

    private function calculatedPaymentStatus(array $payload): string
    {
        $occurred = $this->cents($payload, 'occurred_amount');
        $base = $occurred > 0 ? $occurred : $this->cents($payload, 'contract_amount');
        $paid = $this->cents($payload, 'paid_amount');

        if ($paid <= 0) {
            return '未回款';
        }

        if ($paid >= $base) {
            return '已回款';
        }

        return '部分回款';
    }

    private function cents(array $payload, string $key): int
    {
        return (int) round((float) ($payload[$key] ?? 0) * 100);
    }

    private function decimal(int $cents): float
    {
        return round($cents / 100, 2);
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
