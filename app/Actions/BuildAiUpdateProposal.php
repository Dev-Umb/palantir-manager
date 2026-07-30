<?php

namespace App\Actions;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\ProjectVisibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BuildAiUpdateProposal
{
    public const UPDATABLE_OBJECTS = [
        'customer',
        'customer_contact',
        'material',
    ];

    private const UPDATABLE_FIELDS = [
        'customer' => ['name', 'address', 'level', 'cooperation_history', 'remark'],
        'customer_contact' => ['name', 'phone'],
        'material' => ['name', 'spec', 'length_mm', 'width_mm', 'unit_weight_type', 'unit_weight', 'remark'],
    ];

    public function __construct(private ProjectVisibility $projectVisibility) {}

    /**
     * @return array{ok: true, artifact: array<string, mixed>, object: BusinessObject, record: ObjectRecord, patch: array<string, mixed>}
     */
    public function handle(User $user, string $objectKey, string $recordId, array $input): array
    {
        if (! in_array($objectKey, self::UPDATABLE_OBJECTS, true)) {
            throw ValidationException::withMessages([
                'object' => '第一版仅支持修改客户信息、客户联系人和物料资料。',
            ]);
        }

        $object = BusinessObject::where('key', $objectKey)->firstOrFail();
        $record = $object->records()->whereKey($recordId)->firstOrFail();
        $this->authorizeUpdate($user, $object, $record);
        $patch = $this->validatedPatch($object, $input);
        $changes = $this->changes($object, $record->payload ?? [], $patch);

        if ($changes === []) {
            throw ValidationException::withMessages([
                'payload' => '没有发现实际变化，请修改至少一个字段。',
            ]);
        }

        $artifact = [
            'id' => (string) Str::uuid7(),
            'type' => 'update_proposal',
            'title' => "待确认：修改{$object->label}",
            'revision' => 1,
            'data' => [
                'status' => 'pending',
                'object' => ['key' => $object->key, 'label' => $object->label],
                'record' => $this->recordSummary($record),
                'patch' => $patch,
                'changes' => $changes,
                'expires_at' => now()->addMinutes(30)->toISOString(),
            ],
        ];

        return [
            'ok' => true,
            'artifact' => $artifact,
            'object' => $object,
            'record' => $record,
            'patch' => $patch,
        ];
    }

    public function authorizeUpdate(User $user, BusinessObject $object, ObjectRecord $record): void
    {
        if (! $user->canDo("object.{$object->key}.update")
            || $object->read_only
            || ! $this->projectVisibility->allowsRecord($user, $record)) {
            throw new AuthorizationException('当前账号没有修改该业务资料的权限。');
        }
    }

    /** @return array<string, mixed> */
    private function validatedPatch(BusinessObject $object, array $input): array
    {
        $allowedKeys = self::UPDATABLE_FIELDS[$object->key] ?? [];
        $unknownKeys = collect(array_keys($input))->diff($allowedKeys)->values();
        if ($unknownKeys->isNotEmpty()) {
            throw ValidationException::withMessages([
                'payload' => '包含不允许修改的字段：'.$unknownKeys->implode('、').'。',
            ]);
        }

        if ($input === []) {
            throw ValidationException::withMessages([
                'payload' => '请至少提供一个需要修改的字段。',
            ]);
        }

        $patch = Arr::only($input, $allowedKeys);
        Validator::make(
            ['payload' => $patch],
            $this->rules($object->key, array_keys($patch)),
            [
                'payload.*.required' => ':attribute必须填写。',
                'payload.*.in' => ':attribute不在允许选项中。',
                'payload.*.numeric' => ':attribute必须是数字。',
                'payload.*.min' => ':attribute不能小于 :min。',
            ],
            collect($object->fields)->mapWithKeys(
                fn (array $field) => ["payload.{$field['key']}" => $field['label']],
            )->all(),
        )->validate();

        return $patch;
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(string $objectKey, array $keys): array
    {
        $rules = match ($objectKey) {
            'customer' => [
                'payload.name' => ['required', 'string', 'max:200'],
                'payload.address' => ['nullable', 'string', 'max:500'],
                'payload.level' => ['nullable', Rule::in(['A', 'B', 'C'])],
                'payload.cooperation_history' => ['nullable', 'string', 'max:2000'],
                'payload.remark' => ['nullable', 'string', 'max:1000'],
            ],
            'customer_contact' => [
                'payload.name' => ['required', 'string', 'max:160'],
                'payload.phone' => ['nullable', 'string', 'max:60'],
            ],
            'material' => [
                'payload.name' => ['required', 'string', 'max:200'],
                'payload.spec' => ['nullable', 'string', 'max:200'],
                'payload.length_mm' => ['nullable', 'numeric', 'min:0'],
                'payload.width_mm' => ['nullable', 'numeric', 'min:0'],
                'payload.unit_weight_type' => ['nullable', Rule::in(['每平米', '每米', '每张', '每支'])],
                'payload.unit_weight' => ['nullable', 'numeric', 'min:0'],
                'payload.remark' => ['nullable', 'string', 'max:1000'],
            ],
            default => [],
        };

        return Arr::only($rules, collect($keys)->map(fn (string $key) => "payload.{$key}")->all());
    }

    /** @return array<int, array{key: string, label: string, before: mixed, after: mixed}> */
    private function changes(BusinessObject $object, array $current, array $patch): array
    {
        $fields = collect($object->fields)->keyBy('key');

        return collect($patch)
            ->reject(fn (mixed $value, string $key) => $this->sameValue($current[$key] ?? null, $value))
            ->map(fn (mixed $value, string $key) => [
                'key' => $key,
                'label' => $fields[$key]['label'] ?? $key,
                'before' => $current[$key] ?? null,
                'after' => $value,
            ])
            ->values()
            ->all();
    }

    private function sameValue(mixed $before, mixed $after): bool
    {
        return json_encode($before, JSON_PRESERVE_ZERO_FRACTION) === json_encode($after, JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array{id: string, code: string, title: string, url: string} */
    private function recordSummary(ObjectRecord $record): array
    {
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
}
