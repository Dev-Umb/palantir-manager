<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Support\ObjectRelations;

class SyncObjectReferenceNames
{
    /** Matches the retained business workspace verified against production. */
    public const OBJECT_KEYS = ['customer', 'customer_contact', 'tender', 'project', 'project_business_summary', 'contract'];

    public function __construct(private ObjectRelations $relations) {}

    public function supports(BusinessObject $object): bool
    {
        return in_array($object->key, self::OBJECT_KEYS, true);
    }

    /** @return array{payload: array<string, mixed>, invalid: array<int, string>} */
    public function normalize(BusinessObject $object, array $payload): array
    {
        if (! $this->supports($object)) {
            return ['payload' => $payload, 'invalid' => []];
        }
        $fields = collect($this->relations->relationFields($object))
            ->filter(fn (array $field) => in_array($field['target'], self::OBJECT_KEYS, true)
                && ($field['scope'] ?? null) !== 'item');
        $ids = $fields->flatMap(function (array $field) use ($payload): array {
            $value = $payload[$field['key']] ?? null;

            return is_array($value) ? $value : [$value];
        })->filter(fn ($id) => is_string($id) && $id !== '')->unique();
        $records = ObjectRecord::with('businessObject')->whereIn('id', $ids)->get()->keyBy('id');
        $invalid = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            $multiple = $field['type'] === 'multirelation';
            $value = $payload[$key] ?? null;
            $ids = $multiple ? (is_array($value) ? $value : []) : [$value];
            $previous = $payload['_snapshots'][$key] ?? null;
            $previousById = $multiple ? collect(is_array($previous) ? $previous : [])->filter(fn ($entry) => is_array($entry) && isset($entry['id']))->keyBy('id') : collect();
            $snapshots = [];
            foreach ($ids as $id) {
                if (! is_string($id) || $id === '') {
                    continue;
                }
                $target = $records->get($id);
                if ($target?->businessObject?->key === $field['target']) {
                    $snapshots[] = ['id' => $id, 'label' => (string) ($target->title ?: $target->code)];
                } else {
                    $invalid[] = $key;
                    $old = $multiple ? $previousById->get($id) : $previous;
                    if (is_array($old) && ($old['id'] ?? null) === $id) {
                        $snapshots[] = $old;
                    }
                }
            }
            if ($snapshots !== []) {
                $payload['_snapshots'][$key] = $multiple ? $snapshots : $snapshots[0];
            } else {
                unset($payload['_snapshots'][$key]);
            }
        }
        if (($payload['_snapshots'] ?? null) === []) {
            unset($payload['_snapshots']);
        }
        if ($object->key === 'contract') {
            $project = $records->get($payload['project_id'] ?? '');
            if ($project?->businessObject?->key === 'project') {
                $payload['project_no'] = (string) (($project->payload['project_no'] ?? '') ?: $project->code);
            } elseif (empty($payload['project_id'])) {
                $payload['project_no'] = '';
            }
        }

        return ['payload' => $payload, 'invalid' => array_values(array_unique($invalid))];
    }

    public function syncDependents(ObjectRecord $source): void
    {
        $source->loadMissing('businessObject');
        if (! $source->businessObject || ! $this->supports($source->businessObject)) {
            return;
        }
        $objects = BusinessObject::whereIn('key', self::OBJECT_KEYS)->get()
            ->filter(fn (BusinessObject $object) => collect($this->relations->relationFields($object))->contains('target', $source->businessObject->key));
        $this->relations->forgetLabels();
        foreach ($objects as $object) {
            $object->records()->whereKeyNot($source->id)
                ->whereRaw('CAST(payload AS TEXT) LIKE ?', ['%'.$source->id.'%'])
                ->orderBy('id')->lockForUpdate()->get()
                ->each(fn (ObjectRecord $record) => $this->refreshRecord($record));
        }
    }

    /** @return array{changed: bool, invalid: array<int, string>} */
    public function refreshRecord(ObjectRecord $record, bool $apply = true): array
    {
        $record->loadMissing('businessObject');
        $before = $record->payload ?? [];
        $result = $this->normalize($record->businessObject, $before);
        $changed = $before !== $result['payload'];
        if ($changed && $apply) {
            $record->newQuery()->whereKey($record->id)->update(['payload' => $result['payload']]);
            $changes = [];
            foreach (array_unique([...array_keys($before), ...array_keys($result['payload'])]) as $key) {
                if (($before[$key] ?? null) !== ($result['payload'][$key] ?? null)) {
                    $changes[$key] = ['before' => $before[$key] ?? null, 'after' => $result['payload'][$key] ?? null];
                }
            }
            AuditLog::create([
                'user_id' => auth()->id(), 'action' => 'object.references.sync',
                'subject_type' => $record->businessObject->key, 'subject_id' => $record->id,
                'payload' => ['changes' => $changes],
            ]);
            $this->relations->forgetLabels();
        }

        return ['changed' => $changed, 'invalid' => $result['invalid']];
    }
}
