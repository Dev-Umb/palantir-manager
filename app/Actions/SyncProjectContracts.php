<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\ObjectRelations;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Throwable;

class SyncProjectContracts
{
    private const ATTACHMENT_KEYS = [
        'processing_letter_attachments',
        'contract_attachments',
        'statement_attachments',
    ];

    private const EDITABLE_KEYS = [
        'status',
        'ctype',
        'amount',
        'signed_date',
        'contract_chase_record',
        'contract_qty',
        'remark',
    ];

    /** @var array<int, string> */
    private array $storedPaths = [];

    public function __construct(
        private CreateObjectRecord $writer,
        private ObjectRelations $relations,
        private SyncProjectContractAmount $contractAmount,
        private SyncProjectFinance $projectFinance,
        private SyncProjectNotifications $projectNotifications,
    ) {}

    /**
     * @return array{
     *     contracts: array<int, array<string, mixed>>,
     *     deleted_contract_ids: array<int, string>,
     *     contract_status: string
     * }
     */
    public function validate(Request $request, ?ObjectRecord $project = null): array
    {
        $input = $request->all();
        $input['contracts'] = $input['contracts'] ?? [];
        $input['deleted_contract_ids'] = $input['deleted_contract_ids'] ?? [];

        $validated = Validator::make($input, [
            'contracts' => ['present', 'array', 'max:50'],
            'contracts.*' => ['array:id,status,ctype,amount,signed_date,contract_chase_record,contract_qty,remark,processing_letter_attachments,contract_attachments,statement_attachments'],
            'contracts.*.id' => ['nullable', 'uuid', 'distinct'],
            'contracts.*.status' => ['required', Rule::in(['未签署', '已有加工函', '已签署'])],
            'contracts.*.ctype' => ['nullable', Rule::in(['销售合同', '加工合同', '补充协议'])],
            'contracts.*.amount' => ['required', 'numeric'],
            'contracts.*.signed_date' => ['nullable', 'date'],
            'contracts.*.contract_chase_record' => ['nullable', 'string'],
            'contracts.*.contract_qty' => ['nullable', 'numeric'],
            'contracts.*.remark' => ['nullable', 'string'],
            'contracts.*.processing_letter_attachments' => ['nullable', 'array', 'max:20'],
            'contracts.*.processing_letter_attachments.*' => [File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(20 * 1024)],
            'contracts.*.contract_attachments' => ['nullable', 'array', 'max:20'],
            'contracts.*.contract_attachments.*' => [File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(20 * 1024)],
            'contracts.*.statement_attachments' => ['nullable', 'array', 'max:20'],
            'contracts.*.statement_attachments.*' => [File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(20 * 1024)],
            'deleted_contract_ids' => ['present', 'array', 'max:50'],
            'deleted_contract_ids.*' => ['uuid', 'distinct'],
        ], [
            'contracts.present' => '合同明细必须明确提交。',
            'contracts.max' => '单个项目最多一次维护 50 份合同。',
            'contracts.*.amount.required' => '每份合同都必须填写合同金额。',
            'contracts.*.amount.numeric' => '合同金额必须是有效数字。',
        ])->validate();

        $contracts = collect($validated['contracts'])
            ->map(function (array $contract): array {
                $normalized = Arr::only($contract, ['id', ...self::EDITABLE_KEYS, ...self::ATTACHMENT_KEYS]);
                $normalized['status'] = $normalized['status'] ?? '未签署';
                foreach (self::ATTACHMENT_KEYS as $key) {
                    $normalized[$key] = array_values($normalized[$key] ?? []);
                }

                return $normalized;
            })
            ->values()
            ->all();
        $deletedIds = array_values($validated['deleted_contract_ids']);
        $submittedIds = collect($contracts)->pluck('id')->filter()->values();

        if ($submittedIds->intersect($deletedIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'deleted_contract_ids' => '同一份合同不能同时保存和删除。',
            ]);
        }

        $existing = $project ? $this->contractsForProject($project)->keyBy('id') : collect();
        $unknownIds = $submittedIds->concat($deletedIds)->unique()->diff($existing->keys());
        if ($unknownIds->isNotEmpty()) {
            throw ValidationException::withMessages([
                'contracts' => '合同记录不存在或不属于当前项目。',
            ]);
        }

        $projectedStatuses = $existing
            ->reject(fn (ObjectRecord $contract): bool => in_array($contract->id, $deletedIds, true))
            ->mapWithKeys(fn (ObjectRecord $contract): array => [
                $contract->id => (string) ($contract->payload['status'] ?? '未签署'),
            ]);

        foreach ($contracts as $index => $contract) {
            $existingContract = isset($contract['id']) ? $existing->get($contract['id']) : null;
            $this->guardEvidence($contract, $existingContract?->payload ?? [], $index);
            $projectedStatuses->put($contract['id'] ?? "new-{$index}", $contract['status']);
        }

        return [
            'contracts' => $contracts,
            'deleted_contract_ids' => $deletedIds,
            'contract_status' => $this->aggregateStatus($projectedStatuses->values()->all()),
        ];
    }

    /**
     * @param array{
     *     contracts: array<int, array<string, mixed>>,
     *     deleted_contract_ids: array<int, string>,
     *     contract_status: string
     * } $batch
     */
    public function handle(ObjectRecord $project, array $batch, User $user): void
    {
        $this->storedPaths = [];

        try {
            DB::transaction(function () use ($project, $batch, $user): void {
                $project = ObjectRecord::query()
                    ->whereKey($project->id)
                    ->whereRelation('businessObject', 'key', 'project')
                    ->lockForUpdate()
                    ->firstOrFail();
                $contractObject = BusinessObject::query()->where('key', 'contract')->lockForUpdate()->firstOrFail();
                $existing = $contractObject->records()
                    ->where('payload->project_id', $project->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $submittedIds = collect($batch['contracts'])->pluck('id')->filter();
                $unknownIds = $submittedIds
                    ->concat($batch['deleted_contract_ids'])
                    ->unique()
                    ->diff($existing->keys());
                if ($unknownIds->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'contracts' => '合同记录已发生变化，请刷新项目后重试。',
                    ]);
                }

                foreach ($batch['contracts'] as $index => $contract) {
                    $existingContract = isset($contract['id']) ? $existing->get($contract['id']) : null;
                    $payload = $this->payload($project, $contract, $existingContract?->payload ?? []);
                    $this->guardEvidence($payload, [], $index);

                    if (! $existingContract) {
                        $this->writer->handle(
                            $contractObject,
                            $payload,
                            $user,
                            action: 'object.create.project_contract',
                            refreshProject: false,
                        );

                        continue;
                    }

                    $payload = $this->writer->normalizePayload(
                        $contractObject,
                        $payload,
                        $existingContract->payload ?? [],
                        $user,
                    );
                    $this->relations->validatePayloadRelations(
                        $contractObject,
                        $payload,
                        $user,
                        $existingContract->payload ?? [],
                    );
                    $this->projectFinance->guardCustomerMatchesProject($project, $payload['customer_id'] ?? null);
                    $oldPayload = $existingContract->payload ?? [];
                    if ($oldPayload === $payload) {
                        continue;
                    }
                    $existingContract->update(['payload' => $payload]);
                    AuditLog::create([
                        'user_id' => $user->id,
                        'action' => 'object.update.project_contract',
                        'subject_type' => 'contract',
                        'subject_id' => $existingContract->id,
                        'payload' => [
                            'code' => $existingContract->code,
                            'changes' => $this->payloadChanges($oldPayload, $payload),
                        ],
                    ]);
                }

                foreach ($batch['deleted_contract_ids'] as $deletedId) {
                    $contract = $existing->get($deletedId);
                    if (! $contract) {
                        continue;
                    }
                    $this->relations->assertNotReferenced($contract);
                    $oldPayload = $contract->payload ?? [];
                    $contract->delete();
                    AuditLog::create([
                        'user_id' => $user->id,
                        'action' => 'object.delete.project_contract',
                        'subject_type' => 'contract',
                        'subject_id' => $contract->id,
                        'payload' => [
                            'code' => $contract->code,
                            'deleted_payload' => $oldPayload,
                        ],
                    ]);
                }

                $this->contractAmount->handle($project->id);
                $this->projectNotifications->handleProjects([$project->id]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($this->storedPaths);
            $this->storedPaths = [];

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function payload(ObjectRecord $project, array $submitted, array $existingPayload): array
    {
        $payload = [...$existingPayload, ...Arr::only($submitted, self::EDITABLE_KEYS)];
        $payload['project_id'] = $project->id;
        $payload['project_no'] = (string) (($project->payload['project_no'] ?? '') ?: $project->code);
        $payload['customer_id'] = $project->payload['customer_id'] ?? null;

        foreach (self::ATTACHMENT_KEYS as $key) {
            $existingFiles = collect($existingPayload[$key] ?? [])
                ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
                ->values();
            $newPaths = collect($submitted[$key] ?? [])
                ->filter(fn (mixed $file): bool => $file instanceof UploadedFile)
                ->map(function (UploadedFile $file): string {
                    $path = $file->store('attachments', 'local');
                    $this->storedPaths[] = $path;

                    return $path;
                });
            $payload[$key] = $existingFiles->concat($newPaths)->values()->all();
        }

        return $this->projectFinance->fillContractProjectDefaults($payload, $existingPayload, $project);
    }

    private function guardEvidence(array $submitted, array $existingPayload, int $index): void
    {
        $counts = [];
        foreach (self::ATTACHMENT_KEYS as $key) {
            $counts[$key] = count($existingPayload[$key] ?? []) + count($submitted[$key] ?? []);
            if ($counts[$key] > 20) {
                throw ValidationException::withMessages([
                    "contracts.{$index}.{$key}" => '每类附件最多保留 20 个文件。',
                ]);
            }
        }

        if (($submitted['status'] ?? '未签署') === '已有加工函'
            && $counts['processing_letter_attachments'] === 0) {
            throw ValidationException::withMessages([
                "contracts.{$index}.processing_letter_attachments" => '合同状态为已有加工函时，必须上传加工函附件。',
            ]);
        }
        if (($submitted['status'] ?? '未签署') === '已签署'
            && $counts['contract_attachments'] === 0) {
            throw ValidationException::withMessages([
                "contracts.{$index}.contract_attachments" => '合同状态为已签署时，必须上传合同附件。',
            ]);
        }
    }

    /** @param array<int, string> $statuses */
    private function aggregateStatus(array $statuses): string
    {
        $total = count($statuses);
        $signed = collect($statuses)->where('已签署')->count();
        $hasProcessingLetter = collect($statuses)->contains(
            fn (string $status): bool => in_array($status, ['已有加工函', '已签署'], true),
        );

        return match (true) {
            $total > 0 && $signed === $total => '已签署',
            $signed > 0 => '部分签署',
            $hasProcessingLetter => '已有加工函',
            default => '未签署',
        };
    }

    /** @return Collection<int, ObjectRecord> */
    private function contractsForProject(ObjectRecord $project): Collection
    {
        return ObjectRecord::query()
            ->whereRelation('businessObject', 'key', 'contract')
            ->where('payload->project_id', $project->id)
            ->get();
    }

    /** @return array<string, array{before: mixed, after: mixed}> */
    private function payloadChanges(array $before, array $after): array
    {
        return collect([...array_keys($before), ...array_keys($after)])
            ->unique()
            ->sort()
            ->mapWithKeys(function (string $key) use ($before, $after): array {
                $beforeValue = $before[$key] ?? null;
                $afterValue = $after[$key] ?? null;

                return $beforeValue === $afterValue ? [] : [$key => [
                    'before' => $beforeValue,
                    'after' => $afterValue,
                ]];
            })
            ->all();
    }
}
