<?php

namespace App\Actions;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\ObjectRelations;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BuildAiWriteProposal
{
    public const WRITABLE_OBJECTS = [
        'customer',
        'customer_contact',
    ];

    public function __construct(private ObjectRelations $relations) {}

    /**
     * @return array{ok: true, artifact: array<string, mixed>, object: BusinessObject, payload: array<string, mixed>}
     */
    public function handle(User $user, string $objectKey, array $input): array
    {
        if (! in_array($objectKey, self::WRITABLE_OBJECTS, true)) {
            throw ValidationException::withMessages([
                'object' => '仅支持客户信息和客户联系人。',
            ]);
        }

        $object = BusinessObject::where('key', $objectKey)->firstOrFail();
        $this->authorizeCreate($user, $object);
        $payload = $this->validatedPayload($object, $input);
        $relationPayload = $payload;
        $this->relations->validatePayloadRelations($object, $relationPayload, $user);

        $artifact = [
            'id' => (string) Str::uuid7(),
            'type' => 'write_proposal',
            'title' => "待确认：新增{$object->label}",
            'revision' => 1,
            'data' => [
                'status' => 'pending',
                'object' => ['key' => $object->key, 'label' => $object->label],
                'payload' => $payload,
                'fields' => $this->previewFields($object, $payload),
                'expires_at' => now()->addMinutes(30)->toISOString(),
            ],
        ];

        return [
            'ok' => true,
            'artifact' => $artifact,
            'object' => $object,
            'payload' => $payload,
        ];
    }

    private function authorizeCreate(User $user, BusinessObject $object): void
    {
        $allowed = $user->canDo("object.{$object->key}.create");

        if (! $allowed || $object->read_only) {
            throw new AuthorizationException('当前账号没有新增该业务资料的权限。');
        }
    }

    /** @return array<string, mixed> */
    private function validatedPayload(BusinessObject $object, array $input): array
    {
        $allowedKeys = collect($object->fields)
            ->reject(fn (array $field) => ($field['readonly'] ?? false)
                || ($field['scope'] ?? null) === 'item'
                || in_array($field['type'] ?? null, ['readonly', 'lookup', 'derived', 'file'], true))
            ->pluck('key');

        $unknownKeys = collect(array_keys($input))->diff($allowedKeys)->values();
        if ($unknownKeys->isNotEmpty()) {
            throw ValidationException::withMessages([
                'payload' => '包含不允许填写的字段：'.$unknownKeys->implode('、').'。',
            ]);
        }

        $payload = Arr::only($input, $allowedKeys->all());
        foreach ($object->fields as $field) {
            if (! array_key_exists($field['key'], $payload) && array_key_exists('default', $field)) {
                $payload[$field['key']] = $field['default'];
            }
        }

        $validator = Validator::make(
            ['payload' => $payload],
            $this->rules($object->key, $payload),
            [
                'payload.*.required' => ':attribute必须填写。',
                'payload.*.in' => ':attribute不在允许选项中。',
                'payload.*.numeric' => ':attribute必须是数字。',
                'payload.*.min' => ':attribute不能小于 :min。',
                'payload.*.date' => ':attribute日期格式不正确。',
            ],
            $this->attributes($object),
        );
        $validator->validate();

        return $payload;
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(string $objectKey, array $payload): array
    {
        return match ($objectKey) {
            'customer_contact' => [
                'payload.name' => ['required', 'string', 'max:160'],
                'payload.phone' => ['nullable', 'string', 'max:60'],
                'payload.customer_id' => ['required', 'string'],
            ],
            'customer' => [
                'payload.name' => ['required', 'string', 'max:200'],
                'payload.address' => ['nullable', 'string', 'max:500'],
                'payload.level' => ['nullable', Rule::in(['A', 'B', 'C'])],
                'payload.cooperation_history' => ['nullable', 'string', 'max:2000'],
                'payload.remark' => ['nullable', 'string', 'max:1000'],
            ],
            default => [],
        };
    }

    /** @return array<string, string> */
    private function attributes(BusinessObject $object): array
    {
        $attributes = collect($object->fields)->mapWithKeys(
            fn (array $field) => ["payload.{$field['key']}" => $field['label']],
        );

        return $attributes->merge([
        ])->all();
    }

    /** @return array<int, array{key: string, label: string, value: mixed}> */
    private function previewFields(BusinessObject $object, array $payload): array
    {
        $fields = collect($object->fields)
            ->filter(fn (array $field) => array_key_exists($field['key'], $payload))
            ->map(fn (array $field) => [
                'key' => $field['key'],
                'label' => $field['label'],
                'value' => $this->displayValue($field, $payload[$field['key']]),
            ]);

        return $fields->values()->all();
    }

    private function displayValue(array $field, mixed $value): mixed
    {
        if (in_array($field['type'] ?? null, ['relation', 'creatable_relation'], true)
            && is_string($value) && $value !== '') {
            return $this->relatedLabel($value);
        }

        return $value;
    }

    private function relatedLabel(string $id): string
    {
        $record = ObjectRecord::whereKey($id)->first();

        return $record
            ? ($record->code !== '' ? "{$record->code} · {$record->title}" : $record->title)
            : '关联记录不存在';
    }
}
