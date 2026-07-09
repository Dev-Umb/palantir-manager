<?php

namespace App\Support;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ObjectRelations
{
    /** @var array<string, array<string, string|null>|null> */
    private array $labelCache = [];

    public function __construct(private ProjectVisibility $projectVisibility)
    {
    }

    public function optionsFor(BusinessObject $object, ?Collection $objects = null, ?User $user = null): array
    {
        $options = [];
        $itemsByTarget = [];

        foreach ($this->relationFields($object) as $field) {
            $targetKey = $field['target'] ?? '';
            $target = $objects?->firstWhere('key', $field['target'] ?? '')
                ?? BusinessObject::where('key', $field['target'] ?? '')->first();
            if (! array_key_exists($targetKey, $itemsByTarget)) {
                $itemsByTarget[$targetKey] = $target ? $this->optionsForObject($target, $user) : [];
            }

            $options[$field['key']] = [
                'target' => $field['target'] ?? null,
                'target_label' => $target?->label,
                'items' => $itemsByTarget[$targetKey],
            ];
        }

        return $options;
    }

    public function optionsForObjectKey(string $objectKey): array
    {
        $object = BusinessObject::where('key', $objectKey)->first();

        return $object ? $this->optionsForObject($object) : [];
    }

    public function formatRecord(ObjectRecord $record): array
    {
        $record->loadMissing('businessObject');
        $payload = $record->payload ?? [];
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
            $display[$field['key']] = $field['type'] === 'relation'
                ? ($this->labelForId($value)['label'] ?? ($value ? '关联记录不存在' : ''))
                : $value;
        }

        return [
            'id' => $record->id,
            'code' => $record->code,
            'title' => $record->title,
            'payload' => $payload,
            'display' => $display,
            'created_at' => $record->created_at?->toISOString(),
        ];
    }

    public function preloadLabels(Collection $records): void
    {
        $ids = $records
            ->flatMap(function (ObjectRecord $record) {
                $record->loadMissing('businessObject');
                if (! $record->businessObject) {
                    return [];
                }

                $payload = $record->payload ?? [];

                return collect($this->relationFields($record->businessObject))
                    ->map(fn (array $field) => $payload[$field['key']] ?? null)
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
        }
    }

    public function chain(?ObjectRecord $record, ?Collection $objects = null): ?array
    {
        if (! $record) {
            return null;
        }

        $record->loadMissing('businessObject');
        $payload = $record->payload ?? [];

        $upstream = collect($this->relationFields($record->businessObject))
            ->map(function (array $field) use ($payload) {
                $id = $payload[$field['key']] ?? null;

                if (! $id) {
                    return null;
                }

                return [
                    'field' => $field['label'],
                    'target' => $field['target'] ?? null,
                    'record' => $this->briefById($id),
                ];
            })
            ->filter()
            ->values();

        $downstream = $this->downstream($record, $objects);

        return [
            'record' => $this->brief($record),
            'upstream' => $upstream->values()->all(),
            'downstream' => $downstream->values()->all(),
        ];
    }

    public function validatePayloadRelations(BusinessObject $object, array $payload): void
    {
        $errors = [];

        foreach ($this->relationFields($object) as $field) {
            $value = $payload[$field['key']] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $target = BusinessObject::where('key', $field['target'] ?? '')->first();
            $exists = $target && $target->records()->whereKey($value)->exists();

            if (! $exists) {
                $targetLabel = $target?->label ?? '关联对象';
                $errors["payload.{$field['key']}"] = "{$field['label']}必须选择有效的{$targetLabel}。";
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function relationFields(BusinessObject $object): array
    {
        return collect($object->fields ?? [])
            ->filter(fn (array $field) => ($field['type'] ?? null) === 'relation' && ! empty($field['target']))
            ->values()
            ->all();
    }

    private function optionsForObject(BusinessObject $object, ?User $user = null): array
    {
        $query = $object->records()->latest();
        if ($object->key === 'project' && $user) {
            $this->projectVisibility->scope($query, $user);
        }

        return $query->get(['id', 'business_object_id', 'code', 'title'])
            ->map(fn (ObjectRecord $record) => [
                'id' => $record->id,
                'label' => $this->recordLabel($record, $object),
                'code' => $record->code,
                'title' => $record->title,
            ])
            ->values()
            ->all();
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
                    ->filter(fn (ObjectRecord $candidate) => ($candidate->payload[$field['key']] ?? null) === $record->id)
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
                $record->payload['release_status'] ?? null,
            ])->filter()->implode(' · ');
        }

        return trim($record->code.' · '.($record->title ?: $objectLabel));
    }
}
