<?php

namespace App\Support;

use App\Actions\AcknowledgeWorkflowTask;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ObjectRelations
{
    private const OPTION_LIMIT = 50;

    private const INACTIVE_RELATION_TARGETS = [
        'material',
        'customer_contact',
        'production_team',
        'team_member',
    ];

    /** @var array<string, array<string, string|null>|null> */
    private array $labelCache = [];

    /** @var array<int, string> */
    private array $accountLabelCache = [];

    /** @var array<string, array<int, string>> */
    private array $projectIdsByContact = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $contactsByCustomer = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $projectsByCustomer = [];

    /** @var array<string, array{id: string, name: string, phone: string, customer_id: string}> */
    private array $contactDetailsById = [];

    public function __construct(
        private ProjectVisibility $projectVisibility,
        private ReferenceGraphLock $referenceGraphLock,
        private AcknowledgeWorkflowTask $workflowTasks,
    ) {}

    public function lockReferenceGraph(): void
    {
        $this->referenceGraphLock->acquire();
    }

    public function optionsFor(
        BusinessObject $object,
        ?Collection $objects = null,
        ?User $user = null,
        ?ObjectRecord $editingRecord = null,
    ): array {
        $options = [];

        foreach ($this->relationFields($object) as $field) {
            $target = $objects?->firstWhere('key', $field['target'] ?? '')
                ?? BusinessObject::where('key', $field['target'] ?? '')->first();
            $result = $target
                ? $this->optionsForField($object, $field, $target, $user, $editingRecord)
                : ['items' => [], 'selectedItems' => []];
            $searchParameters = [
                'source_object' => $object->key,
                'field' => $field['key'],
            ];
            if ($editingRecord?->business_object_id === $object->id) {
                $searchParameters['editing_record'] = $editingRecord->id;
            }

            $options[$field['key']] = [
                'target' => $field['target'] ?? null,
                'target_label' => $target?->label,
                'items' => $result['items'],
                'selectedItems' => $result['selectedItems'],
                'search_url' => route('relation-options.index', $searchParameters, false),
            ];
        }

        return $options;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, selectedItems: array<int, array<string, mixed>>}
     */
    public function searchOptions(
        BusinessObject $source,
        array $field,
        User $user,
        ?ObjectRecord $editingRecord = null,
        string $query = '',
        array $context = [],
    ): array {
        $target = BusinessObject::where('key', $field['target'] ?? '')->first();
        if (! $target) {
            return ['items' => [], 'selectedItems' => []];
        }

        return $this->optionsForField(
            $source,
            $field,
            $target,
            $user,
            $editingRecord,
            trim($query),
            $context,
        );
    }

    public function optionsForObjectKey(string $objectKey, ?User $user = null): array
    {
        $object = BusinessObject::where('key', $objectKey)->first();

        return $object ? $this->optionsForObject($object, $user) : [];
    }

    public function formatRecord(ObjectRecord $record, ?User $user = null): array
    {
        $record->loadMissing('businessObject');
        $payload = $this->payloadWithDerivedRelations($record);
        $display = [];

        foreach ($record->businessObject->fields ?? [] as $field) {
            if (($field['system'] ?? null) === 'code') {
                $display[$field['key']] = $record->code;

                continue;
            }

            if (($field['system'] ?? null) === 'title') {
                $display[$field['key']] = $record->title;

                continue;
            }

            $value = $payload[$field['key']] ?? null;
            if (($field['type'] ?? null) === 'file' && is_string($value) && $value !== '') {
                $value = route('attachments.download', [$record->id, $field['key']], false);
                $payload[$field['key']] = $value;
            }
            if (($field['type'] ?? null) === 'files' && is_array($value)) {
                $value = collect($value)
                    ->values()
                    ->map(fn (mixed $path, int $index): ?string => is_string($path) && $path !== ''
                        ? route('attachments.download', [$record->id, $field['key'], $index], false)
                        : null)
                    ->filter()
                    ->values()
                    ->all();
                $payload[$field['key']] = $value;
            }
            $display[$field['key']] = match ($field['type'] ?? null) {
                'relation', 'creatable_relation' => $this->relationDisplayLabel(
                    $payload,
                    $field,
                    $value,
                    $record->businessObject->key === 'project'
                        && ($field['key'] ?? null) === 'customer_id'
                        && ($field['target'] ?? null) === 'customer',
                ),
                'multirelation' => $this->multirelationDisplayLabels($payload, $field, $value),
                'account' => $this->accountLabel($value),
                default => $value,
            };
        }

        $formatted = [
            'id' => $record->id,
            'code' => $record->code,
            'title' => $record->title,
            'payload' => $payload,
            'display' => $display,
            'created_at' => $record->created_at?->toISOString(),
            'is_new_task' => $this->workflowTasks->visibleTo($record, $user),
        ];

        if ($record->businessObject->key === 'customer') {
            $formatted['contacts'] = $this->contactsByCustomer[$record->id] ?? [];
            $formatted['cooperation_projects'] = $this->projectsByCustomer[$record->id] ?? [];
        }

        if ($record->businessObject->key === 'project') {
            $formatted['contacts'] = collect($payload['customer_contact_ids'] ?? [])
                ->map(fn ($contactId) => is_string($contactId)
                    ? ($this->contactDetailsById[$contactId] ?? null)
                    : null)
                ->filter()
                ->values()
                ->all();
        }

        return $formatted;
    }

    private function relationDisplayLabel(
        array $payload,
        array $field,
        mixed $value,
        bool $preferLiveLabel = false,
    ): string {
        if (! is_string($value) || $value === '') {
            return '';
        }

        if ($preferLiveLabel) {
            $liveLabel = $this->labelForId($value)['label'] ?? null;
            if (is_string($liveLabel)) {
                return $liveLabel;
            }
        }

        $snapshot = $payload['_snapshots'][$field['key']] ?? null;
        if (is_array($snapshot) && ($snapshot['id'] ?? null) === $value && is_string($snapshot['label'] ?? null)) {
            return $snapshot['label'];
        }

        return $this->labelForId($value)['label'] ?? '关联记录不存在';
    }

    /** @return array<int, string> */
    private function multirelationDisplayLabels(array $payload, array $field, mixed $value): array
    {
        $snapshots = collect($payload['_snapshots'][$field['key']] ?? [])
            ->filter(fn ($snapshot) => is_array($snapshot)
                && is_string($snapshot['id'] ?? null)
                && is_string($snapshot['label'] ?? null))
            ->keyBy('id');

        return collect(is_array($value) ? $value : [])
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->map(function (string $id) use ($snapshots): string {
                $snapshot = $snapshots->get($id);

                return is_array($snapshot)
                    ? $snapshot['label']
                    : ($this->labelForId($id)['label'] ?? '关联记录不存在');
            })
            ->values()
            ->all();
    }

    public function relationDisplayValue(array $payload, array $field): string|array
    {
        $value = $payload[$field['key']] ?? null;

        return ($field['type'] ?? null) === 'multirelation'
            ? $this->multirelationDisplayLabels($payload, $field, $value)
            : $this->relationDisplayLabel($payload, $field, $value);
    }

    public function preloadLabels(Collection $records, ?User $user = null): void
    {
        $records->each(fn (ObjectRecord $record) => $record->loadMissing('businessObject'));
        $this->preloadDerivedRelations($records, $user);

        $this->preloadRelationLabels($records);
    }

    public function preloadRelationLabels(Collection $records): void
    {
        $records->each(fn (ObjectRecord $record) => $record->loadMissing('businessObject'));

        $accountIds = $records
            ->flatMap(function (ObjectRecord $record): array {
                $payload = $record->payload ?? [];

                return collect($record->businessObject?->fields ?? [])
                    ->filter(fn (array $field): bool => ($field['type'] ?? null) === 'account')
                    ->map(fn (array $field): mixed => $payload[$field['key']] ?? null)
                    ->filter(fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false)
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();
            })
            ->unique()
            ->reject(fn (int $id): bool => array_key_exists($id, $this->accountLabelCache))
            ->values();
        if ($accountIds->isNotEmpty()) {
            User::query()->whereIn('id', $accountIds)->pluck('name', 'id')
                ->each(fn (string $name, int $id) => $this->accountLabelCache[$id] = $name);
        }

        $ids = $records
            ->flatMap(function (ObjectRecord $record) {
                if (! $record->businessObject) {
                    return [];
                }

                $payload = $this->payloadWithDerivedRelations($record);

                return collect($this->relationFields($record->businessObject))
                    ->flatMap(function (array $field) use ($payload) {
                        if (($field['scope'] ?? null) === 'item') {
                            return collect($payload['items'] ?? [])->flatMap(function ($item) use ($field): array {
                                $value = is_array($item) ? ($item[$field['key']] ?? null) : null;

                                return ($field['type'] ?? null) === 'multirelation' && is_array($value)
                                    ? $value
                                    : [$value];
                            });
                        }

                        $value = $payload[$field['key']] ?? null;

                        return ($field['type'] ?? null) === 'multirelation'
                            ? (is_array($value) ? $value : [])
                            : [$value];
                    })
                    ->filter(fn ($id) => is_string($id) && $id !== '');
            })
            ->unique()
            ->reject(fn (string $id) => array_key_exists($id, $this->labelCache))
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $recordsById = ObjectRecord::with('businessObject')
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            $record = $recordsById->get($id);
            $this->labelCache[$id] = $record ? $this->brief($record) : null;
            if ($record?->businessObject?->key === 'customer_contact') {
                $this->contactDetailsById[$record->id] = $this->contactDetails($record);
            }
        }
    }

    private function accountLabel(mixed $value): string
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return '';
        }

        $id = (int) $value;
        if (! array_key_exists($id, $this->accountLabelCache)) {
            $this->accountLabelCache[$id] = User::query()->whereKey($id)->value('name') ?? '';
        }

        return $this->accountLabelCache[$id];
    }

    public function chain(?ObjectRecord $record, ?Collection $objects = null): ?array
    {
        if (! $record) {
            return null;
        }

        $record->loadMissing('businessObject');
        $payload = $record->payload ?? [];

        $upstream = collect($this->relationFields($record->businessObject))
            ->flatMap(function (array $field) use ($payload) {
                $value = $payload[$field['key']] ?? null;
                $ids = ($field['type'] ?? null) === 'multirelation'
                    ? (is_array($value) ? $value : [])
                    : [$value];

                return collect($ids)
                    ->filter(fn ($id) => is_string($id) && $id !== '')
                    ->map(fn (string $id) => [
                        'field' => $field['label'],
                        'target' => $field['target'] ?? null,
                        'record' => $this->briefById($id),
                    ]);
            })
            ->values();

        $downstream = $this->downstream($record, $objects);

        return [
            'record' => $this->brief($record),
            'upstream' => $upstream->values()->all(),
            'downstream' => $downstream->values()->all(),
        ];
    }

    public function validatePayloadRelations(
        BusinessObject $object,
        array $payload,
        ?User $user = null,
        array $existingPayload = [],
    ): void {
        $errors = [];

        foreach ($this->relationFields($object) as $field) {
            if (($field['scope'] ?? null) === 'item') {
                continue;
            }

            $value = $payload[$field['key']] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $multiple = ($field['type'] ?? null) === 'multirelation';
            if ($multiple && ! is_array($value)) {
                $errors["payload.{$field['key']}"] = "{$field['label']}必须是有效的多选列表。";

                continue;
            }

            if (! $multiple && ! is_string($value)) {
                $errors["payload.{$field['key']}"] = "{$field['label']}必须选择有效的关联记录。";

                continue;
            }

            $submittedIds = collect($multiple ? $value : [$value]);
            $hasMalformedId = $submittedIds->contains(
                fn (mixed $id): bool => ! is_string($id) || ($id !== '' && ! Str::isUuid($id)),
            );
            if ($hasMalformedId) {
                $errors["payload.{$field['key']}"] = '关联记录格式不正确';

                continue;
            }

            $ids = $submittedIds
                ->filter(fn ($id) => is_string($id) && $id !== '')
                ->unique()
                ->values();
            $target = BusinessObject::where('key', $field['target'] ?? '')->first();
            $records = $target
                ? $target->records()->whereIn('id', $ids->all())->get()->keyBy('id')
                : collect();

            if (! $target || $records->count() !== $ids->count()) {
                $targetLabel = $target?->label ?? '关联对象';
                $errors["payload.{$field['key']}"] = "{$field['label']}必须选择有效的{$targetLabel}。";

                continue;
            }

            if ($user) {
                $forbidden = $records->first(
                    fn (ObjectRecord $related) => ! $this->projectVisibility->allowsRecord($user, $related),
                );
                if ($forbidden) {
                    $errors["payload.{$field['key']}"] = ($field['target'] ?? null) === 'project'
                        ? "{$field['label']}包含当前用户不可访问的项目。"
                        : "{$field['label']}包含当前用户不可访问的关联记录。";

                    continue;
                }
            }

            if (in_array($target->key, self::INACTIVE_RELATION_TARGETS, true)) {
                $existingIds = $this->relationIdsFromPayload($field, $existingPayload);
                $newInactive = $records->first(fn (ObjectRecord $related) => ($related->payload['status'] ?? '启用') === '停用'
                    && ! $existingIds->contains($related->id));
                if ($newInactive) {
                    $errors["payload.{$field['key']}"] = "停用的{$target->label}不能建立新的关联。";

                    continue;
                }
            }

            if ($object->key === 'project' && $field['key'] === 'customer_contact_ids') {
                $customerId = $payload['customer_id'] ?? null;
                $mismatched = $records->first(
                    fn (ObjectRecord $contact) => ($contact->payload['customer_id'] ?? null) !== $customerId,
                );
                if ($mismatched) {
                    $errors["payload.{$field['key']}"] = '客户联系人必须属于当前选择的客户。';

                    continue;
                }

            }
        }

        if ($object->key === 'outbound'
            && ! isset($errors['payload.project_id'])
            && ! isset($errors['payload.drawing_id'])) {
            $drawingId = $payload['drawing_id'] ?? null;
            $projectId = $payload['project_id'] ?? null;
            $drawing = is_string($drawingId) && $drawingId !== ''
                ? ObjectRecord::query()
                    ->whereKey($drawingId)
                    ->whereRelation('businessObject', 'key', 'drawing')
                    ->first()
                : null;
            if ($drawing && ($drawing->payload['project_id'] ?? null) !== $projectId) {
                $errors['payload.drawing_id'] = '图纸编号必须属于当前选择的项目。';
            }
        }

        if ($object->key === 'work_order'
            && ! isset($errors['payload.team_id'])
            && ! isset($errors['payload.production_owner_id'])) {
            $teamId = $payload['team_id'] ?? null;
            $ownerId = $payload['production_owner_id'] ?? null;
            $unchanged = ($existingPayload['team_id'] ?? null) === $teamId
                && ($existingPayload['production_owner_id'] ?? null) === $ownerId;
            if (is_string($ownerId) && $ownerId !== '' && ! $unchanged) {
                $owner = ObjectRecord::query()
                    ->whereKey($ownerId)
                    ->whereRelation('businessObject', 'key', 'team_member')
                    ->first();
                if (! is_string($teamId) || $teamId === '' || ($owner?->payload['team_id'] ?? null) !== $teamId) {
                    $errors['payload.production_owner_id'] = '生产负责人必须是当前加工班组中已启用的成员。';
                }
            }
        }

        if (in_array($object->key, ['receivable', 'contract'], true)
            && ! isset($errors['payload.project_id'])
            && ! isset($errors['payload.customer_id'])) {
            $projectId = $payload['project_id'] ?? null;
            $project = is_string($projectId) && $projectId !== ''
                ? ObjectRecord::whereKey($projectId)
                    ->whereRelation('businessObject', 'key', 'project')
                    ->first()
                : null;

            if ($project && ($project->payload['customer_id'] ?? null) !== ($payload['customer_id'] ?? null)) {
                $errors['payload.customer_id'] = '客户必须与所选项目的客户一致。';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function validateItemRelations(
        BusinessObject $object,
        array $payload,
        ?User $user = null,
        array $existingPayload = [],
    ): void {
        $fields = collect($this->relationFields($object))
            ->filter(fn (array $field) => ($field['scope'] ?? null) === 'item');
        if ($fields->isEmpty()) {
            return;
        }

        $errors = [];
        foreach ($fields->groupBy('target') as $targetKey => $targetFields) {
            $target = BusinessObject::where('key', $targetKey)->first();
            $submittedIds = collect($payload['items'] ?? [])
                ->flatMap(function (array $item, int|string $index) use ($targetFields, &$errors): Collection {
                    return $targetFields->pluck('key')
                        ->map(function (string $key) use ($item, $index, &$errors): mixed {
                            $value = $item[$key] ?? null;
                            if ($value !== null && $value !== ''
                                && (! is_string($value) || ! Str::isUuid($value))) {
                                $errors["payload.items.{$index}.{$key}"] = '关联记录格式不正确';
                            }

                            return $value;
                        });
                });
            $ids = $submittedIds
                ->filter(fn (mixed $value): bool => is_string($value) && Str::isUuid($value))
                ->unique()
                ->values();
            $records = $target
                ? $target->records()->whereIn('id', $ids->all())->get()->keyBy('id')
                : collect();

            foreach ($targetFields as $field) {
                foreach (($payload['items'] ?? []) as $index => $item) {
                    $value = $item[$field['key']] ?? null;
                    if ($value === null || $value === '') {
                        continue;
                    }

                    if (isset($errors["payload.items.{$index}.{$field['key']}"])) {
                        continue;
                    }

                    $record = is_string($value) ? $records->get($value) : null;
                    if (! $record) {
                        $targetLabel = $target?->label ?? '关联对象';
                        $errors["payload.items.{$index}.{$field['key']}"] = "{$field['label']}必须选择有效的{$targetLabel}。";

                        continue;
                    }

                    if ($user && ! $this->projectVisibility->allowsRecord($user, $record)) {
                        $errors["payload.items.{$index}.{$field['key']}"] = ($field['target'] ?? null) === 'project'
                            ? "{$field['label']}包含当前用户不可访问的项目。"
                            : "{$field['label']}包含当前用户不可访问的关联记录。";

                        continue;
                    }

                    if ($target
                        && in_array($target->key, self::INACTIVE_RELATION_TARGETS, true)
                        && ($record->payload['status'] ?? '启用') === '停用'
                        && ! $this->itemKeepsExistingRelation($payload, $existingPayload, $index, $field['key'], $record->id)) {
                        $errors["payload.items.{$index}.{$field['key']}"] = "停用的{$target->label}不能建立新的关联。";
                    }
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function assertNotReferenced(ObjectRecord $record): void
    {
        $record->loadMissing('businessObject');
        $targetKey = $record->businessObject?->key;
        if (! $targetKey) {
            return;
        }

        $sources = BusinessObject::query()
            ->get(['id', 'key', 'label', 'fields'])
            ->mapWithKeys(function (BusinessObject $source) use ($targetKey): array {
                $fields = collect($this->relationFields($source))
                    ->filter(fn (array $field) => ($field['target'] ?? null) === $targetKey)
                    ->values()
                    ->all();

                return $fields === [] ? [] : [$source->id => [
                    'object' => $source,
                    'fields' => $fields,
                ]];
            });
        if ($sources->isEmpty()) {
            return;
        }

        $dependency = ObjectRecord::query()
            ->whereIn('business_object_id', $sources->keys()->all())
            ->whereKeyNot($record->id)
            ->orderBy('id')
            ->cursor()
            ->map(function (ObjectRecord $sourceRecord) use ($sources, $record): ?array {
                $source = $sources->get($sourceRecord->business_object_id);
                foreach ($source['fields'] as $field) {
                    if ($this->payloadReferencesId($sourceRecord->payload ?? [], $field, $record->id)) {
                        return [
                            'object' => $source['object'],
                            'record' => $sourceRecord,
                            'field' => $field,
                        ];
                    }
                }

                return null;
            })
            ->filter()
            ->first();

        if (! $dependency) {
            return;
        }

        $sourceObject = $dependency['object'];
        $sourceRecord = $dependency['record'];
        $field = $dependency['field'];
        $recordLabel = trim("{$sourceRecord->code} {$sourceRecord->title}");
        throw ValidationException::withMessages([
            'record' => "无法删除：{$sourceObject->label}「{$recordLabel}」的{$field['label']}仍在引用该记录，请先解除关联。",
        ]);
    }

    /** @return array{payload: array<string, mixed>, cleared_count: int} */
    public function clearMismatchedProjectContactsOnCustomerChange(
        BusinessObject $object,
        array $payload,
        array $existingPayload,
    ): array {
        $oldCustomerId = $existingPayload['customer_id'] ?? null;
        $newCustomerId = $payload['customer_id'] ?? null;
        $contactIds = $payload['customer_contact_ids'] ?? null;

        if ($object->key !== 'project'
            || ! array_key_exists('customer_id', $existingPayload)
            || $oldCustomerId === $newCustomerId
            || ! is_array($contactIds)
            || $contactIds === []) {
            return ['payload' => $payload, 'cleared_count' => 0];
        }

        $contactObject = BusinessObject::where('key', 'customer_contact')->first();
        if (! $contactObject) {
            return ['payload' => $payload, 'cleared_count' => 0];
        }

        $mismatchedIds = $contactObject->records()
            ->whereIn('id', $contactIds)
            ->get()
            ->filter(fn (ObjectRecord $contact) => ($contact->payload['customer_id'] ?? null) !== $newCustomerId)
            ->pluck('id');

        $payload['customer_contact_ids'] = collect($contactIds)
            ->reject(fn (string $contactId) => $mismatchedIds->contains($contactId))
            ->values()
            ->all();

        return [
            'payload' => $payload,
            'cleared_count' => $mismatchedIds->count(),
        ];
    }

    public function relationFields(BusinessObject $object): array
    {
        return collect($object->fields ?? [])
            ->filter(fn (array $field) => in_array($field['type'] ?? null, ['relation', 'multirelation', 'creatable_relation'], true)
                && ! empty($field['target']))
            ->values()
            ->all();
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, selectedItems: array<int, array<string, mixed>>}
     */
    private function optionsForField(
        BusinessObject $source,
        array $field,
        BusinessObject $target,
        ?User $user,
        ?ObjectRecord $editingRecord,
        string $search = '',
        array $context = [],
    ): array {
        $editingRecord = $editingRecord?->business_object_id === $source->id ? $editingRecord : null;
        $effectiveContext = [...($editingRecord?->payload ?? []), ...$context];
        $selectedIds = $this->selectedOptionIds($field, $editingRecord);

        $selectedRecords = collect();
        if ($selectedIds->isNotEmpty()) {
            $selectedQuery = $target->records()->whereIn('id', $selectedIds->all());
            $this->scopeOptionQuery($selectedQuery, $target, $user);
            $this->applyStructuralOptionFilters(
                $selectedQuery,
                $source,
                $field,
                $editingRecord,
                $effectiveContext,
            );
            $selectedRecords = $selectedQuery
                ->limit(self::OPTION_LIMIT)
                ->get(['id', 'business_object_id', 'code', 'title', 'payload']);
        }

        $availableQuery = $target->records()
            ->orderByDesc('created_at')
            ->orderBy('id');
        $this->scopeOptionQuery($availableQuery, $target, $user);
        $this->applyStructuralOptionFilters(
            $availableQuery,
            $source,
            $field,
            $editingRecord,
            $effectiveContext,
        );
        $this->applyAvailabilityOptionFilters($availableQuery, $source, $field, $target, $editingRecord);
        $this->applyOptionSearch($availableQuery, $target, $search);

        $availableItems = collect($this->formatOptionRecords(
            $availableQuery
                ->limit(self::OPTION_LIMIT)
                ->get(['id', 'business_object_id', 'code', 'title', 'payload']),
            $target,
        ));
        $availableIds = $availableItems->pluck('id')->flip();
        $selectedItems = collect($this->formatOptionRecords($selectedRecords, $target))
            ->reject(fn (array $item) => $availableIds->has($item['id']))
            ->values();

        return [
            'items' => $availableItems->all(),
            'selectedItems' => $selectedItems->take(self::OPTION_LIMIT)->all(),
        ];
    }

    private function selectedOptionIds(array $field, ?ObjectRecord $editingRecord): Collection
    {
        if (! $editingRecord) {
            return collect();
        }

        $payload = $editingRecord->payload ?? [];
        if (($field['scope'] ?? null) === 'item') {
            return collect($payload['items'] ?? [])
                ->pluck($field['key'])
                ->filter(fn ($id) => is_string($id) && $id !== '')
                ->unique()
                ->values();
        }

        $value = $payload[$field['key']] ?? null;
        $ids = ($field['type'] ?? null) === 'multirelation'
            ? (is_array($value) ? $value : [])
            : [$value];

        return collect($ids)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values();
    }

    private function relationIdsFromPayload(array $field, array $payload): Collection
    {
        $value = $payload[$field['key']] ?? null;
        $ids = ($field['type'] ?? null) === 'multirelation'
            ? (is_array($value) ? $value : [])
            : [$value];

        return collect($ids)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values();
    }

    private function itemKeepsExistingRelation(
        array $payload,
        array $existingPayload,
        int $index,
        string $fieldKey,
        string $relatedId,
    ): bool {
        $itemId = $payload['items'][$index]['id'] ?? null;
        if (! is_string($itemId) || $itemId === '') {
            return false;
        }

        $existingItem = collect($existingPayload['items'] ?? [])->first(
            fn ($item) => is_array($item) && ($item['id'] ?? null) === $itemId,
        );

        return is_array($existingItem) && ($existingItem[$fieldKey] ?? null) === $relatedId;
    }

    private function payloadReferencesId(array $payload, array $field, string $targetId): bool
    {
        if (($field['scope'] ?? null) === 'item') {
            return collect($payload['items'] ?? [])->contains(function ($item) use ($field, $targetId): bool {
                if (! is_array($item)) {
                    return false;
                }

                return $this->relationValueContainsId($item[$field['key']] ?? null, $field, $targetId);
            });
        }

        return $this->relationValueContainsId($payload[$field['key']] ?? null, $field, $targetId);
    }

    private function relationValueContainsId(mixed $value, array $field, string $targetId): bool
    {
        if (($field['type'] ?? null) === 'multirelation') {
            return is_array($value) && in_array($targetId, $value, true);
        }

        return is_string($value) && hash_equals($targetId, $value);
    }

    private function scopeOptionQuery($query, BusinessObject $target, ?User $user): void
    {
        if ($user) {
            $this->projectVisibility->scopeRecords($query, $target, $user);
        }
    }

    private function applyStructuralOptionFilters(
        $query,
        BusinessObject $source,
        array $field,
        ?ObjectRecord $editingRecord,
        array $context,
    ): void {
        if ($source->key === 'production_team' && ($field['target'] ?? null) === 'team_member') {
            if (! $editingRecord) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where('payload->team_id', $editingRecord->id);
        }

        if ($source->key === 'project' && ($field['key'] ?? null) === 'customer_contact_ids') {
            $customerId = $context['customer_id'] ?? null;
            if (! is_string($customerId) || $customerId === '') {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('payload->customer_id', $customerId);
            }
        }

        if ($source->key === 'work_order' && ($field['key'] ?? null) === 'production_owner_id') {
            $teamId = $context['team_id'] ?? null;
            if (! is_string($teamId) || $teamId === '') {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('payload->team_id', $teamId);
            }
        }
    }

    private function applyAvailabilityOptionFilters(
        $query,
        BusinessObject $source,
        array $field,
        BusinessObject $target,
        ?ObjectRecord $editingRecord,
    ): void {
        if (in_array($target->key, ['material', 'customer_contact', 'production_team', 'team_member'], true)) {
            $query->where(function ($query) {
                $query->whereNull('payload->status')
                    ->orWhere('payload->status', '!=', '停用');
            });
        }

        if ($source->key === 'receivable' && ($field['target'] ?? null) === 'project') {
            $occupiedProjectIds = $source->records()
                ->when($editingRecord, fn ($query) => $query->whereKeyNot($editingRecord->id))
                ->get(['payload'])
                ->pluck('payload.project_id')
                ->filter()
                ->unique()
                ->values();
            if ($occupiedProjectIds->isNotEmpty()) {
                $query->whereNotIn('id', $occupiedProjectIds->all());
            }
        }

        if ($source->key === 'work_order' && ($field['target'] ?? null) === 'drawing') {
            $query->where('payload->design_status', '已下放');
        }
    }

    private function applyOptionSearch($query, BusinessObject $target, string $search): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }

        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $pattern = '%'.$search.'%';
        $fieldKeys = collect([
            $target->title_field,
            'name',
            'project_no',
            'drawing_no',
            'material_code',
            'phone',
            'spec',
        ])->filter()
            ->unique()
            ->intersect(collect($target->fields ?? [])->pluck('key'))
            ->values();

        $query->where(function ($query) use ($operator, $pattern, $fieldKeys) {
            $query->where('code', $operator, $pattern)
                ->orWhere('title', $operator, $pattern);
            foreach ($fieldKeys as $key) {
                $query->orWhere("payload->{$key}", $operator, $pattern);
            }
        });
    }

    private function optionsForObject(BusinessObject $object, ?User $user = null): array
    {
        $query = $object->records()->latest();
        if ($user) {
            $this->projectVisibility->scopeRecords($query, $object, $user);
        }

        if (in_array($object->key, ['material', 'customer_contact', 'production_team', 'team_member'], true)) {
            $query->where(function ($query) {
                $query->whereNull('payload->status')
                    ->orWhere('payload->status', '!=', '停用');
            });
        }

        return $this->formatOptionRecords(
            $query->limit(self::OPTION_LIMIT)->get(['id', 'business_object_id', 'code', 'title', 'payload']),
            $object,
        );
    }

    private function formatOptionRecords(Collection $records, BusinessObject $object): array
    {
        $leaders = collect();
        if ($object->key === 'production_team') {
            $leaderIds = $records->pluck('payload.leader_id')->filter()->unique()->values();
            if ($leaderIds->isNotEmpty()) {
                $leaders = ObjectRecord::whereIn('id', $leaderIds)->get()->keyBy('id');
            }
        }

        return $records
            ->map(fn (ObjectRecord $record) => [
                'id' => $record->id,
                'label' => $this->recordLabel($record, $object),
                'code' => $record->code,
                'title' => $record->title,
                'meta' => $this->optionMeta($record, $object, $leaders),
            ])
            ->values()
            ->all();
    }

    private function payloadWithDerivedRelations(ObjectRecord $record): array
    {
        $payload = $record->payload ?? [];
        if ($record->businessObject?->key === 'customer_contact') {
            $payload['project_ids'] = $this->projectIdsByContact[$record->id] ?? [];
        }

        return $payload;
    }

    private function preloadDerivedRelations(Collection $records, ?User $user): void
    {
        $customerIds = $records
            ->filter(fn (ObjectRecord $record) => $record->businessObject?->key === 'customer')
            ->pluck('id')
            ->values();
        foreach ($customerIds as $customerId) {
            $this->contactsByCustomer[$customerId] = [];
            $this->projectsByCustomer[$customerId] = [];
        }

        $contacts = $records
            ->filter(fn (ObjectRecord $record) => $record->businessObject?->key === 'customer_contact')
            ->values();

        if ($customerIds->isNotEmpty()) {
            $contactObject = BusinessObject::where('key', 'customer_contact')->first();
            if ($contactObject) {
                $contacts = $contacts
                    ->concat($contactObject->records()
                        ->whereIn('payload->customer_id', $customerIds->all())
                        ->latest()
                        ->get())
                    ->unique('id')
                    ->values();
            }
        }

        $contactIds = $contacts->pluck('id')->values();
        foreach ($contacts as $contact) {
            $this->contactDetailsById[$contact->id] = $this->contactDetails($contact);
        }
        foreach ($contactIds as $contactId) {
            $this->projectIdsByContact[$contactId] = [];
        }

        $projects = collect();
        $projectObject = ($customerIds->isNotEmpty() || $contactIds->isNotEmpty())
            ? BusinessObject::where('key', 'project')->first()
            : null;
        if ($projectObject && $customerIds->isNotEmpty()) {
            $customerProjectQuery = $projectObject->records()
                ->with('businessObject')
                ->whereIn('payload->customer_id', $customerIds->all())
                ->latest();
            if ($user) {
                $this->projectVisibility->scope($customerProjectQuery, $user);
            }

            $projects = $customerProjectQuery->get();
        }
        if ($projectObject && $contactIds->isNotEmpty()) {
            $contactProjectQuery = $projectObject->records()->with('businessObject')->latest();
            if ($user) {
                $this->projectVisibility->scope($contactProjectQuery, $user);
            }

            $projects = $projects
                ->concat($contactProjectQuery->get()
                    ->filter(function (ObjectRecord $project) use ($contactIds) {
                        $linkedIds = $project->payload['customer_contact_ids'] ?? [];

                        return is_array($linkedIds) && collect($linkedIds)->intersect($contactIds)->isNotEmpty();
                    }))
                ->unique('id')
                ->values();
        }

        foreach ($projects as $project) {
            $this->labelCache[$project->id] = $this->brief($project);
            $customerId = $project->payload['customer_id'] ?? null;
            if (is_string($customerId) && array_key_exists($customerId, $this->projectsByCustomer)) {
                $this->projectsByCustomer[$customerId][] = [
                    'id' => $project->id,
                    'code' => (string) (($project->payload['project_no'] ?? '') ?: $project->code),
                    'title' => (string) (($project->payload['name'] ?? '') ?: $project->title),
                    'date' => (string) (($project->payload['handover_date'] ?? '') ?: $project->created_at?->toDateString()),
                ];
            }
            foreach ((array) ($project->payload['customer_contact_ids'] ?? []) as $contactId) {
                if (array_key_exists($contactId, $this->projectIdsByContact)) {
                    $this->projectIdsByContact[$contactId][] = $project->id;
                }
            }
        }

        foreach ($contacts as $contact) {
            $projectIds = array_values(array_unique($this->projectIdsByContact[$contact->id] ?? []));
            $this->projectIdsByContact[$contact->id] = $projectIds;
            $customerId = $contact->payload['customer_id'] ?? null;
            if (! is_string($customerId) || ! array_key_exists($customerId, $this->contactsByCustomer)) {
                continue;
            }

            $details = $this->contactDetailsById[$contact->id];

            $this->contactsByCustomer[$customerId][] = [
                ...$details,
                'project_ids' => $projectIds,
                'projects' => collect($projectIds)
                    ->map(fn (string $projectId) => $this->labelCache[$projectId] ?? null)
                    ->filter()
                    ->map(fn (array $project) => [
                        'id' => $project['id'],
                        'title' => $project['title'],
                    ])
                    ->values()
                    ->all(),
            ];
        }
    }

    /** @return array{id: string, name: string, phone: string, customer_id: string} */
    private function contactDetails(ObjectRecord $contact): array
    {
        $payload = $contact->payload ?? [];

        return [
            'id' => $contact->id,
            'name' => (string) (($payload['name'] ?? '') ?: $contact->title),
            'phone' => (string) ($payload['phone'] ?? ''),
            'customer_id' => (string) ($payload['customer_id'] ?? ''),
        ];
    }

    private function optionMeta(ObjectRecord $record, BusinessObject $object, ?Collection $related = null): array
    {
        return match ($object->key) {
            'project' => [
                'customer_id' => $record->payload['customer_id'] ?? null,
                'project_no' => $record->payload['project_no'] ?? $record->code,
            ],
            'customer_contact' => [
                'customer_id' => $record->payload['customer_id'] ?? null,
                'phone' => $record->payload['phone'] ?? '',
            ],
            'production_team' => [
                'leader_id' => $record->payload['leader_id'] ?? null,
                'leader_name' => ($leader = $related?->get($record->payload['leader_id'] ?? null))
                    ? (($leader->payload['name'] ?? '') ?: $leader->title)
                    : '',
            ],
            'team_member' => [
                'team_id' => $record->payload['team_id'] ?? null,
                'status' => $record->payload['status'] ?? '启用',
            ],
            default => [],
        };
    }

    private function downstream(ObjectRecord $record, ?Collection $objects = null): Collection
    {
        $relations = ($objects ?? BusinessObject::orderBy('sort_order')->get())
            ->flatMap(fn (BusinessObject $object) => collect($this->relationFields($object))
                ->filter(fn (array $field) => ($field['target'] ?? null) === $record->businessObject->key)
                ->map(fn (array $field) => ['object' => $object, 'field' => $field]))
            ->values();

        if ($relations->isEmpty()) {
            return collect();
        }

        $candidates = ObjectRecord::with('businessObject')
            ->whereIn('business_object_id', $relations->pluck('object.id')->unique())
            ->latest()
            ->get()
            ->groupBy('business_object_id');

        return $relations
            ->map(function (array $relation) use ($candidates, $record) {
                $object = $relation['object'];
                $field = $relation['field'];
                $matches = ($candidates[$object->id] ?? collect())
                    ->filter(function (ObjectRecord $candidate) use ($field, $record) {
                        $value = $candidate->payload[$field['key']] ?? null;

                        return ($field['type'] ?? null) === 'multirelation'
                            ? is_array($value) && in_array($record->id, $value, true)
                            : $value === $record->id;
                    })
                    ->take(10)
                    ->map(fn (ObjectRecord $candidate) => $this->brief($candidate))
                    ->values();

                if ($matches->isEmpty()) {
                    return null;
                }

                return [
                    'object_key' => $object->key,
                    'object_label' => $object->label,
                    'field' => $field['label'],
                    'records' => $matches,
                ];
            })
            ->filter()
            ->values();
    }

    private function briefById(?string $id): ?array
    {
        if (! $id) {
            return null;
        }

        $record = $this->labelForId($id);

        return $record ?: [
            'id' => $id,
            'object_key' => null,
            'object_label' => null,
            'code' => null,
            'title' => null,
            'label' => '关联记录不存在',
        ];
    }

    private function labelForId(?string $id): ?array
    {
        if (! $id) {
            return null;
        }

        if (! array_key_exists($id, $this->labelCache)) {
            $record = ObjectRecord::with('businessObject')->find($id);
            $this->labelCache[$id] = $record ? $this->brief($record) : null;
        }

        return $this->labelCache[$id];
    }

    private function brief(ObjectRecord $record): array
    {
        $record->loadMissing('businessObject');

        return [
            'id' => $record->id,
            'object_key' => $record->businessObject?->key,
            'object_label' => $record->businessObject?->label,
            'code' => $record->code,
            'title' => $record->title,
            'label' => $this->recordLabel($record),
        ];
    }

    private function recordLabel(ObjectRecord $record, ?BusinessObject $object = null): string
    {
        $objectKey = $object?->key ?? ($record->relationLoaded('businessObject') ? $record->businessObject?->key : null);
        $objectLabel = $object?->label ?? ($record->relationLoaded('businessObject') ? $record->businessObject?->label : null);

        if ($objectKey === 'drawing') {
            return collect([
                $record->payload['drawing_no'] ?? $record->code,
                $record->title ?: $objectLabel,
                $record->payload['design_status'] ?? null,
            ])->filter()->implode(' · ');
        }

        if ($objectKey === 'project') {
            return collect([
                $record->payload['project_no'] ?? $record->code,
                $record->title ?: $objectLabel,
            ])->filter()->implode(' · ');
        }

        return trim($record->code.' · '.($record->title ?: $objectLabel));
    }
}
