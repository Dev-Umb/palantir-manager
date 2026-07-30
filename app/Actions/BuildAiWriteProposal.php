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
        'requisition',
        'team_log',
        'customer',
        'customer_contact',
        'material',
    ];

    public function __construct(private ObjectRelations $relations) {}

    /**
     * @return array{ok: true, artifact: array<string, mixed>, object: BusinessObject, payload: array<string, mixed>}
     */
    public function handle(User $user, string $objectKey, array $input): array
    {
        if (! in_array($objectKey, self::WRITABLE_OBJECTS, true)) {
            throw ValidationException::withMessages([
                'object' => '第一版仅支持采购申请、现场报工、客户信息、客户联系人和物料资料。',
            ]);
        }

        $object = BusinessObject::where('key', $objectKey)->firstOrFail();
        $this->authorizeCreate($user, $object);
        $payload = $this->validatedPayload($object, $input);
        $relationPayload = Arr::except($payload, [
            'shortage_material_id',
            'shortage_qty',
            'shortage_unit',
        ]);
        $this->relations->validatePayloadRelations($object, $relationPayload, $user);
        if ($objectKey === 'team_log' && ($payload['exception_type'] ?? null) === '缺料') {
            $requisition = BusinessObject::where('key', 'requisition')->firstOrFail();
            $this->relations->validatePayloadRelations($requisition, [
                'material_id' => $payload['shortage_material_id'],
                'project_id' => $payload['project_id'],
            ], $user);
        }

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
        $allowed = $object->key === 'requisition'
            ? $user->canDo('requisition.create') || $user->canDo('object.requisition.create')
            : $user->canDo("object.{$object->key}.create");

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
        if ($object->key === 'team_log') {
            $allowedKeys = $allowedKeys->merge([
                'shortage_material_id',
                'shortage_qty',
                'shortage_unit',
            ]);
        }

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

        if ($object->key === 'requisition') {
            $payload['status'] = '待处理';
        }
        if ($object->key === 'team_log') {
            $payload['exception_type'] ??= '无';
            $payload['work_date'] ??= now()->toDateString();
            if ($payload['exception_type'] !== '无') {
                $payload['status'] = '异常暂停';
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
            'requisition' => [
                'payload.requester' => ['required', Rule::in(['生产', '技术', '业务'])],
                'payload.material_id' => ['required', 'string'],
                'payload.qty' => ['required', 'numeric', 'min:0.01'],
                'payload.unit' => ['nullable', Rule::in(['张', '支', '根', 'kg', '吨', '桶', '盒'])],
                'payload.project_id' => ['nullable', 'string'],
                'payload.urgency' => ['required', Rule::in(['普通', '紧急', '特急'])],
                'payload.reason' => ['nullable', 'string', 'max:500'],
                'payload.status' => ['required', Rule::in(['待处理'])],
            ],
            'team_log' => [
                'payload.project_id' => ['required', 'string'],
                'payload.team_id' => ['required', 'string'],
                'payload.status' => ['required', Rule::in(['开始生产', '生产中', '异常暂停', '完成任务'])],
                'payload.process' => ['required', Rule::in(['切割', '焊接', '总装', '打磨', '其他'])],
                'payload.completed_qty' => ['nullable', 'numeric', 'min:0'],
                'payload.unit' => ['nullable', Rule::in(['件', '套', 'kg', '吨', '张', '根'])],
                'payload.exception_type' => ['required', Rule::in(['无', '缺料', '图纸问题', '设备故障', '质量问题', '人员不足', '其他'])],
                'payload.work_date' => ['nullable', 'date'],
                'payload.part_name' => ['nullable', 'string', 'max:160'],
                'payload.remark' => ['nullable', 'string', 'max:1000'],
                'payload.shortage_material_id' => [Rule::requiredIf(($payload['exception_type'] ?? null) === '缺料'), 'nullable', 'string'],
                'payload.shortage_qty' => [Rule::requiredIf(($payload['exception_type'] ?? null) === '缺料'), 'nullable', 'numeric', 'min:0.01'],
                'payload.shortage_unit' => [Rule::requiredIf(($payload['exception_type'] ?? null) === '缺料'), 'nullable', Rule::in(['吨', 'kg', '张', '根'])],
            ],
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
            'material' => [
                'payload.name' => ['required', 'string', 'max:200'],
                'payload.spec' => ['nullable', 'string', 'max:200'],
                'payload.length_mm' => ['nullable', 'numeric', 'min:0'],
                'payload.width_mm' => ['nullable', 'numeric', 'min:0'],
                'payload.status' => ['required', Rule::in(['启用', '停用'])],
                'payload.unit_weight_type' => ['nullable', Rule::in(['每平米', '每米', '每张', '每支'])],
                'payload.unit_weight' => ['nullable', 'numeric', 'min:0'],
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
            'payload.shortage_material_id' => '缺料物料',
            'payload.shortage_qty' => '缺料数量',
            'payload.shortage_unit' => '缺料单位',
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

        if ($object->key === 'team_log' && ($payload['exception_type'] ?? null) === '缺料') {
            $extra = [
                ['key' => 'shortage_material_id', 'label' => '缺料物料', 'value' => $this->relatedLabel($payload['shortage_material_id'])],
                ['key' => 'shortage_qty', 'label' => '缺料数量', 'value' => $payload['shortage_qty']],
                ['key' => 'shortage_unit', 'label' => '缺料单位', 'value' => $payload['shortage_unit']],
            ];
            $fields = $fields->concat($extra);
        }

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
