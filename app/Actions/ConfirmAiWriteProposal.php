<?php

namespace App\Actions;

use App\Models\AiRun;
use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\MaterialNames;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmAiWriteProposal
{
    public function __construct(
        private BuildAiWriteProposal $proposals,
        private BuildAiUpdateProposal $updateProposals,
        private CreateObjectRecord $records,
        private MaterialNames $materialNames,
    ) {}

    /**
     * @return array{ok: bool, code?: string, message: string, run: AiRun, artifact: array<string, mixed>}
     */
    public function confirm(AiRun $run, User $user, string $proposalId): array
    {
        return DB::transaction(function () use ($run, $user, $proposalId): array {
            $lockedRun = AiRun::query()->lockForUpdate()->findOrFail($run->id);
            $this->authorize($lockedRun, $user);
            [$index, $artifact] = $this->proposal($lockedRun, $proposalId);

            if (($artifact['data']['status'] ?? null) === 'confirmed') {
                return $this->result(true, '该资料已经写入，无需重复确认。', $lockedRun, $artifact);
            }
            if (($artifact['data']['status'] ?? null) !== 'pending') {
                return $this->result(false, '该待办已失效，不能继续确认。', $lockedRun, $artifact, 'proposal_not_pending');
            }
            if (now()->isAfter($artifact['data']['expires_at'] ?? now()->subSecond())) {
                $artifact = $this->withStatus($artifact, 'expired');
                $this->replaceArtifact($lockedRun, $index, $artifact);

                return $this->result(false, '该待办已超过 30 分钟，请让 AI 重新生成。', $lockedRun, $artifact, 'proposal_expired');
            }

            $objectKey = (string) ($artifact['data']['object']['key'] ?? '');
            if (($artifact['type'] ?? null) === 'update_proposal') {
                return $this->confirmUpdate($lockedRun, $user, $proposalId, $index, $artifact, $objectKey);
            }

            $payload = (array) ($artifact['data']['payload'] ?? []);
            $rebuilt = $this->proposals->handle($user, $objectKey, $payload);
            $payload = $rebuilt['payload'];
            $object = $rebuilt['object'];

            [$record, $relatedRecords] = $this->createRecords($object, $payload, $user);
            $artifact = $this->withStatus($artifact, 'confirmed', [
                'confirmed_at' => now()->toISOString(),
                'record' => $this->recordSummary($record),
                'related_records' => collect($relatedRecords)->map($this->recordSummary(...))->all(),
            ]);
            $this->replaceArtifact($lockedRun, $index, $artifact);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'ai.write_proposal.confirmed',
                'subject_type' => $object->key,
                'subject_id' => $record->id,
                'payload' => [
                    'run_id' => $lockedRun->id,
                    'proposal_id' => $proposalId,
                    'record_code' => $record->code,
                    'related_record_ids' => collect($relatedRecords)->pluck('id')->all(),
                ],
            ]);

            return $this->result(true, "{$object->label}已写入。", $lockedRun, $artifact);
        });
    }

    /**
     * @return array{ok: bool, code?: string, message: string, run: AiRun, artifact: array<string, mixed>}
     */
    public function reject(AiRun $run, User $user, string $proposalId): array
    {
        return DB::transaction(function () use ($run, $user, $proposalId): array {
            $lockedRun = AiRun::query()->lockForUpdate()->findOrFail($run->id);
            $this->authorize($lockedRun, $user);
            [$index, $artifact] = $this->proposal($lockedRun, $proposalId);

            if (($artifact['data']['status'] ?? null) === 'pending') {
                $artifact = $this->withStatus($artifact, 'rejected', [
                    'rejected_at' => now()->toISOString(),
                ]);
                $this->replaceArtifact($lockedRun, $index, $artifact);

                $action = ($artifact['type'] ?? null) === 'update_proposal'
                    ? 'ai.update_proposal.rejected'
                    : 'ai.write_proposal.rejected';
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => $action,
                    'subject_type' => (string) ($artifact['data']['object']['key'] ?? 'unknown'),
                    'subject_id' => $proposalId,
                    'payload' => ['run_id' => $lockedRun->id],
                ]);
            }

            $message = ($artifact['type'] ?? null) === 'update_proposal'
                ? '已放弃本次修改。'
                : '已放弃本次写入。';

            return $this->result(true, $message, $lockedRun, $artifact);
        });
    }

    private function authorize(AiRun $run, User $user): void
    {
        abort_unless($run->user_id === $user->id, 403);
        if ($run->status !== 'completed') {
            throw ValidationException::withMessages([
                'proposal' => 'AI 任务完成后才能确认写入。',
            ]);
        }
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function proposal(AiRun $run, string $proposalId): array
    {
        $index = collect($run->artifacts ?? [])->search(
            fn (array $artifact) => ($artifact['id'] ?? null) === $proposalId
                && in_array($artifact['type'] ?? null, ['write_proposal', 'update_proposal'], true),
        );
        if ($index === false) {
            abort(404);
        }

        return [$index, $run->artifacts[$index]];
    }

    /**
     * @return array{ok: bool, code?: string, message: string, run: AiRun, artifact: array<string, mixed>}
     */
    private function confirmUpdate(
        AiRun $run,
        User $user,
        string $proposalId,
        int $index,
        array $artifact,
        string $objectKey,
    ): array {
        $recordId = (string) ($artifact['data']['record']['id'] ?? '');
        $patch = (array) ($artifact['data']['patch'] ?? []);
        if ($objectKey === 'material') {
            BusinessObject::query()->where('key', $objectKey)->lockForUpdate()->firstOrFail();
        }
        $record = ObjectRecord::query()->lockForUpdate()->findOrFail($recordId);
        $record->loadMissing('businessObject');

        if ($record->businessObject->key !== $objectKey) {
            abort(404);
        }

        $object = $record->businessObject;
        $this->updateProposals->authorizeUpdate($user, $object, $record);
        $currentPayload = $record->payload ?? [];
        if ($this->hasStaleChanges($artifact, $currentPayload)) {
            $artifact = $this->withStatus($artifact, 'stale');
            $this->replaceArtifact($run, $index, $artifact);

            return $this->result(
                false,
                '该资料已被其他操作修改，请让 AI 重新读取后再生成修改草稿。',
                $run,
                $artifact,
                'proposal_stale',
            );
        }

        $rebuilt = $this->updateProposals->handle($user, $objectKey, $recordId, $patch);
        $payload = array_replace($currentPayload, $rebuilt['patch']);
        $payload = $this->records->normalizePayload($object, $payload, $currentPayload);
        $payload = $this->materialNames->normalizeAndGuardUnique($object, $payload, $record->id);
        $record->update([
            'payload' => $payload,
            'title' => (string) ($payload[$object->title_field] ?? $record->title),
        ]);

        $artifact = $this->withStatus($artifact, 'confirmed', [
            'confirmed_at' => now()->toISOString(),
            'record' => $this->recordSummary($record->refresh()),
        ]);
        $this->replaceArtifact($run, $index, $artifact);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'ai.update_proposal.confirmed',
            'subject_type' => $object->key,
            'subject_id' => $record->id,
            'payload' => [
                'run_id' => $run->id,
                'proposal_id' => $proposalId,
                'record_code' => $record->code,
                'changes' => $artifact['data']['changes'] ?? [],
            ],
        ]);

        return $this->result(true, "{$object->label}已更新。", $run, $artifact);
    }

    private function hasStaleChanges(array $artifact, array $currentPayload): bool
    {
        return collect($artifact['data']['changes'] ?? [])->contains(function (array $change) use ($currentPayload): bool {
            $current = $currentPayload[$change['key']] ?? null;

            return json_encode($current, JSON_PRESERVE_ZERO_FRACTION)
                !== json_encode($change['before'] ?? null, JSON_PRESERVE_ZERO_FRACTION);
        });
    }

    /**
     * @return array{0: ObjectRecord, 1: array<int, ObjectRecord>}
     */
    private function createRecords(BusinessObject $object, array $payload, User $user): array
    {
        $relatedRecords = [];
        $recordPayload = Arr::except($payload, [
            'shortage_material_id',
            'shortage_qty',
            'shortage_unit',
        ]);
        $action = match ($object->key) {
            'requisition' => 'requisition.create',
            'team_log' => 'team_log.create',
            default => 'ai.object.create',
        };
        $record = $this->records->handle($object, $recordPayload, $user, $action);

        if ($object->key === 'team_log' && ($payload['exception_type'] ?? null) === '缺料') {
            $requisition = BusinessObject::where('key', 'requisition')->firstOrFail();
            $relatedRecords[] = $this->records->handle($requisition, [
                'requester' => '生产',
                'material_id' => $payload['shortage_material_id'],
                'qty' => $payload['shortage_qty'],
                'unit' => $payload['shortage_unit'],
                'project_id' => $payload['project_id'],
                'urgency' => '紧急',
                'reason' => collect(['现场报工缺料', $payload['part_name'] ?? null, $payload['remark'] ?? null])
                    ->filter()
                    ->implode(' · '),
                'status' => '待处理',
            ], $user, 'team_log.shortage_requisition');
        }

        return [$record, $relatedRecords];
    }

    /** @param array<string, mixed> $extra */
    private function withStatus(array $artifact, string $status, array $extra = []): array
    {
        $artifact['revision'] = (int) ($artifact['revision'] ?? 1) + 1;
        $artifact['data'] = [
            ...($artifact['data'] ?? []),
            'status' => $status,
            ...$extra,
        ];

        return $artifact;
    }

    private function replaceArtifact(AiRun $run, int $index, array $artifact): void
    {
        $artifacts = $run->artifacts ?? [];
        $artifacts[$index] = $artifact;
        $run->update(['artifacts' => array_values($artifacts)]);
    }

    /** @return array{id: string, code: string, title: string, url: string} */
    private function recordSummary(ObjectRecord $record): array
    {
        $record->loadMissing('businessObject');

        return [
            'id' => $record->id,
            'code' => $record->code,
            'title' => $record->title,
            'url' => route('objects.index', [
                'object' => $record->businessObject->key,
                'record' => $record->id,
                'mode' => 'detail',
            ], false),
        ];
    }

    private function result(
        bool $ok,
        string $message,
        AiRun $run,
        array $artifact,
        ?string $code = null,
    ): array {
        return array_filter([
            'ok' => $ok,
            'code' => $code,
            'message' => $message,
            'run' => $run->refresh(),
            'artifact' => $artifact,
        ], fn (mixed $value) => $value !== null);
    }
}
