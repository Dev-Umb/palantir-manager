<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\ObjectRelations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateObjectRecord
{
    public function __construct(
        private AllocateObjectCode $codes,
        private SyncProjectContractAmount $contractAmount,
        private SyncProjectFinance $projectFinance,
        private SyncProjectNotifications $projectNotifications,
        private ObjectRelations $relations,
    ) {}

    public function handle(
        BusinessObject $object,
        array $payload,
        ?User $user = null,
        string $action = 'object.create',
        ?string $workflowKey = null,
        array $workflowTargetRoles = [],
        bool $refreshProject = true,
    ): ObjectRecord {
        return DB::transaction(function () use (
            $object,
            $payload,
            $user,
            $action,
            $workflowKey,
            $workflowTargetRoles,
            $refreshProject,
        ): ObjectRecord {
            $this->relations->lockReferenceGraph();
            $object = BusinessObject::query()->lockForUpdate()->findOrFail($object->id);
            abort_unless(in_array($object->key, SyncObjectReferenceNames::OBJECT_KEYS, true), 404);
            $payload = $this->normalizePayload($object, $payload, user: $user);
            $this->relations->validatePayloadRelations($object, $payload, $user);
            $this->relations->validateItemRelations($object, $payload, $user);
            $project = null;
            if ($object->key === 'contract') {
                $project = $this->projectFinance->lockedProjectOrFail($payload['project_id'] ?? null);
            }
            if ($object->key === 'contract') {
                $payload = $this->projectFinance->fillContractProjectDefaults($payload, project: $project);
            }
            if ($project) {
                $this->projectFinance->guardCustomerMatchesProject($project, $payload['customer_id'] ?? null);
            }

            $code = $this->nextCode($object);
            $payload = $this->fillSystemCode($object, $payload, $code);
            $record = ObjectRecord::create([
                'business_object_id' => $object->id,
                'code' => $code,
                'title' => $this->title($object, $payload),
                'payload' => $payload,
                'workflow_key' => $workflowKey,
                'workflow_target_roles' => $workflowTargetRoles ?: null,
                'created_by' => $user?->id,
            ]);

            AuditLog::create([
                'user_id' => $user?->id,
                'action' => $action,
                'subject_type' => $object->key,
                'subject_id' => $record->id,
                'payload' => ['code' => $record->code, 'title' => $record->title],
            ]);

            if ($object->key === 'contract' && $refreshProject) {
                $this->contractAmount->handle($payload['project_id'] ?? null);
            }

            if ($object->key === 'contract' && $refreshProject) {
                $this->projectNotifications->handleProjects([$payload['project_id'] ?? null]);
            }

            return $record;
        });
    }

    public function normalizePayload(
        BusinessObject $object,
        array $payload,
        array $existingPayload = [],
        ?User $user = null,
    ): array {
        if ($object->key === 'tender') {
            $payload = $this->normalizeTender($payload, $user);
        }

        $payload = $this->roundProjectNumbers($object, $payload);
        $payload = $this->fillProjectNumber($object, $payload, $existingPayload);

        return $this->snapshotRelations($object, $payload, $existingPayload);
    }

    private function roundProjectNumbers(BusinessObject $object, array $payload): array
    {
        if ($object->key !== 'project') {
            return $payload;
        }

        foreach ($object->fields ?? [] as $field) {
            $key = $field['key'] ?? null;
            if (($field['type'] ?? null) !== 'number'
                || ! is_string($key)
                || ! array_key_exists($key, $payload)
                || $payload[$key] === null
                || $payload[$key] === '') {
                continue;
            }

            $payload[$key] = round((float) $payload[$key], 2);
        }

        return $payload;
    }

    private function normalizeTender(array $payload, ?User $user): array
    {
        $payload['status'] = trim((string) ($payload['status'] ?? '')) ?: '跟踪中';
        $payload['purchase_status'] = trim((string) ($payload['purchase_status'] ?? '')) ?: '未购买';

        $customerReference = trim((string) ($payload['customer_id'] ?? ''));
        if ($customerReference === '' || (Str::isUuid($customerReference)
            && ObjectRecord::whereKey($customerReference)
                ->whereRelation('businessObject', 'key', 'customer')->exists())) {
            return $payload;
        }

        if (! $user?->canDo('object.customer.create')) {
            throw ValidationException::withMessages([
                'payload.customer_id' => '当前用户无权新建客户。',
            ]);
        }

        $customerObject = BusinessObject::query()->where('key', 'customer')->firstOrFail();
        $existingCustomer = $customerObject->records()
            ->where('title', $customerReference)
            ->first();
        if ($existingCustomer) {
            $payload['customer_id'] = $existingCustomer->id;

            return $payload;
        }

        $customer = $this->handle(
            $customerObject,
            ['name' => $customerReference],
            $user,
            action: 'object.create.related',
        );
        $payload['customer_id'] = $customer->id;

        return $payload;
    }

    public function nextCode(BusinessObject $object): string
    {
        return $this->codes->handle($object);
    }

    private function title(BusinessObject $object, array $payload): string
    {
        if ($object->title_field === 'code') {
            return $object->label;
        }

        return (string) ($payload[$object->title_field] ?? $payload['name'] ?? $object->label);
    }

    private function fillSystemCode(BusinessObject $object, array $payload, string $code): array
    {
        foreach ($object->fields ?? [] as $field) {
            if (($field['system'] ?? null) === 'code') {
                $payload[$field['key']] = $code;
            }
        }

        return $payload;
    }

    private function fillProjectNumber(BusinessObject $object, array $payload, array $existingPayload = []): array
    {
        $fields = collect($object->fields ?? [])->keyBy('key');
        if (! $fields->has('project_id') || ! $fields->has('project_no')) {
            unset($payload['project_no_norm']);

            return $payload;
        }

        if (($existingPayload['project_id'] ?? null) === ($payload['project_id'] ?? null)
            && array_key_exists('project_no', $existingPayload)) {
            $payload['project_no'] = $existingPayload['project_no'];
        } else {
            $project = $this->linkedRecord($payload['project_id'] ?? null, 'project');
            $payload['project_no'] = $project
                ? (string) (($project->payload['project_no'] ?? '') ?: $project->code)
                : '';
        }
        unset($payload['project_no_norm']);

        return $payload;
    }

    private function linkedRecord(?string $id, string $objectKey): ?ObjectRecord
    {
        if (! $id) {
            return null;
        }

        return ObjectRecord::whereKey($id)
            ->whereRelation('businessObject', 'key', $objectKey)
            ->first();
    }

    private function snapshotRelations(BusinessObject $object, array $payload, array $existingPayload): array
    {
        $fields = collect($object->fields ?? [])->filter(fn (array $field) => in_array($field['type'] ?? null, ['relation', 'creatable_relation', 'multirelation'], true)
            && ! empty($field['target'])
        );
        if ($fields->isEmpty()) {
            return $payload;
        }

        $commonFields = $fields->reject(fn (array $field) => ($field['scope'] ?? null) === 'item');
        $itemFields = $fields->filter(fn (array $field) => ($field['scope'] ?? null) === 'item');
        $relationIds = $commonFields
            ->flatMap(function (array $field) use ($payload): array {
                $value = $payload[$field['key']] ?? null;

                return ($field['type'] ?? null) === 'multirelation' && is_array($value)
                    ? $value
                    : [$value];
            })
            ->concat(collect($payload['items'] ?? [])->flatMap(
                fn (array $item) => $itemFields->flatMap(function (array $field) use ($item): array {
                    $value = $item[$field['key']] ?? null;

                    return ($field['type'] ?? null) === 'multirelation' && is_array($value)
                        ? $value
                        : [$value];
                }),
            ))
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values();

        $records = ObjectRecord::with('businessObject')
            ->whereIn('id', $relationIds->all())
            ->get()
            ->keyBy('id');

        $rootSnapshots = [];
        foreach ($commonFields as $field) {
            $key = $field['key'];
            $id = $payload[$key] ?? null;
            if (($field['type'] ?? null) === 'multirelation') {
                $previousById = collect($existingPayload['_snapshots'][$key] ?? [])
                    ->filter(fn ($snapshot) => is_array($snapshot)
                        && is_string($snapshot['id'] ?? null)
                        && is_string($snapshot['label'] ?? null))
                    ->keyBy('id');
                $snapshots = collect(is_array($id) ? $id : [])
                    ->filter(fn ($relatedId) => is_string($relatedId) && $relatedId !== '')
                    ->map(function (string $relatedId) use ($previousById, $records, $field): ?array {
                        $previous = $previousById->get($relatedId);
                        if (is_array($previous)) {
                            return $previous;
                        }

                        $record = $records->get($relatedId);
                        if ($record?->businessObject?->key !== ($field['target'] ?? null)) {
                            return null;
                        }

                        return ['id' => $relatedId, 'label' => $this->snapshotLabel($record)];
                    })
                    ->filter()
                    ->values()
                    ->all();
                if ($snapshots !== []) {
                    $rootSnapshots[$key] = $snapshots;
                }

                continue;
            }
            if (! is_string($id) || $id === '') {
                continue;
            }

            $previous = $existingPayload['_snapshots'][$key] ?? null;
            if (is_array($previous) && ($previous['id'] ?? null) === $id && is_string($previous['label'] ?? null)) {
                $rootSnapshots[$key] = $previous;

                continue;
            }

            $record = $records->get($id);
            if ($record?->businessObject?->key === ($field['target'] ?? null)) {
                $rootSnapshots[$key] = ['id' => $id, 'label' => $this->snapshotLabel($record)];
            }
        }
        if ($rootSnapshots) {
            $payload['_snapshots'] = $rootSnapshots;
        } else {
            unset($payload['_snapshots']);
        }

        if ($itemFields->isEmpty() || ! is_array($payload['items'] ?? null)) {
            return $payload;
        }

        $existingItems = collect($existingPayload['items'] ?? [])->keyBy('id');

        $payload['items'] = collect($payload['items'])->map(function (array $item) use ($itemFields, $records, $existingItems): array {
            $snapshots = [];
            $existing = $existingItems->get($item['id'] ?? null, []);

            foreach ($itemFields as $field) {
                $key = $field['key'];
                $id = $item[$key] ?? null;
                if (! is_string($id) || $id === '') {
                    continue;
                }

                $previous = $existing['_snapshots'][$key] ?? null;
                if (is_array($previous) && ($previous['id'] ?? null) === $id && is_string($previous['label'] ?? null)) {
                    $snapshots[$key] = $previous;

                    continue;
                }

                $record = $records->get($id);
                if ($record?->businessObject?->key !== ($field['target'] ?? null)) {
                    continue;
                }

                $snapshots[$key] = [
                    'id' => $id,
                    'label' => $this->snapshotLabel($record),
                ];
            }

            if ($snapshots) {
                $item['_snapshots'] = $snapshots;
            } else {
                unset($item['_snapshots']);
            }

            return $item;
        })->values()->all();

        return $payload;
    }

    private function snapshotLabel(ObjectRecord $record): string
    {
        return match ($record->businessObject?->key) {
            'project' => collect([$record->payload['project_no'] ?? $record->code, $record->title])->filter()->implode(' · '),
            default => (string) ($record->title ?: $record->code),
        };
    }
}
