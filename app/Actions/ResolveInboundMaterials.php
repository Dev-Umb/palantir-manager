<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\MaterialNames;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResolveInboundMaterials
{
    public function __construct(
        private AllocateObjectCode $codes,
        private MaterialNames $materialNames,
    ) {}

    public function handle(array $payload, ?User $user = null): array
    {
        $items = $payload['items'] ?? [];
        if (! is_array($items) || $items === []) {
            return $payload;
        }

        $materialObject = BusinessObject::where('key', 'material')->firstOrFail();
        $values = collect($items)->map(fn (array $item) => trim((string) ($item['material_id'] ?? '')));
        $uuidIds = $values->filter(fn (string $value) => Str::isUuid($value))->unique()->values();
        $typedNames = $values->reject(fn (string $value) => $value === '' || Str::isUuid($value))
            ->map(fn (string $value) => $this->materialNames->normalize($value))
            ->filter()
            ->unique()
            ->values();

        if ($typedNames->isNotEmpty()) {
            $materialObject = BusinessObject::query()
                ->whereKey($materialObject->id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        if ($uuidIds->isEmpty() && $typedNames->isEmpty()) {
            return $payload;
        }

        $materials = $materialObject->records()
            ->when($typedNames->isEmpty(), fn ($query) => $query->whereIn('id', $uuidIds->all()))
            ->orderBy('created_at')
            ->get();

        $requestedCanonicals = $typedNames
            ->map(fn (string $name) => $this->materialNames->canonical($name))
            ->flip();
        $requestedIds = $uuidIds->flip();
        $byName = collect();
        foreach ($materials as $record) {
            $canonical = $this->materialNames->canonical(
                (string) (($record->payload['name'] ?? '') ?: $record->title),
            );
            if ($canonical === ''
                || (! $requestedIds->has($record->id) && ! $requestedCanonicals->has($canonical))) {
                continue;
            }
            if ($byName->has($canonical) && $byName->get($canonical)->id !== $record->id) {
                throw ValidationException::withMessages([
                    'payload.items.0.material_id' => '材料主库存在重名记录，请先合并重复材料后再入库。',
                ]);
            }
            $byName->put($canonical, $record);
        }

        foreach ($items as $index => $item) {
            $value = trim((string) ($item['material_id'] ?? ''));
            if ($value === '') {
                continue;
            }

            if (Str::isUuid($value)) {
                $record = $materials->firstWhere('id', $value);
                if (! $record) {
                    throw ValidationException::withMessages([
                        "payload.items.{$index}.material_id" => '物资名称必须选择有效的材料主库记录。',
                    ]);
                }
            } else {
                $name = $this->materialNames->normalize($value);
                if ($name === '') {
                    continue;
                }

                $canonical = $this->materialNames->canonical($name);
                $record = $byName->get($canonical);
                if (! $record) {
                    $record = $this->createMaterial($materialObject, $name, $item, $user);
                    $materials->push($record);
                    $byName->put($canonical, $record);
                }
            }

            $items[$index]['material_id'] = $record->id;
        }

        $payload['items'] = array_values($items);

        return $payload;
    }

    private function createMaterial(
        BusinessObject $object,
        string $name,
        array $item,
        ?User $user,
    ): ObjectRecord {
        $code = $this->codes->handle($object);
        $payload = [
            'material_code' => $code,
            'name' => $name,
            'spec' => $item['spec'] ?? '',
            'length_mm' => $item['length_mm'] ?? null,
            'width_mm' => $item['width_mm'] ?? null,
            'status' => '启用',
            'unit_weight_type' => '',
            'unit_weight' => null,
            'remark' => null,
        ];

        $record = ObjectRecord::create([
            'business_object_id' => $object->id,
            'code' => $code,
            'title' => $name,
            'payload' => $payload,
            'created_by' => $user?->id,
        ]);

        AuditLog::create([
            'user_id' => $user?->id,
            'action' => 'material.auto_create',
            'subject_type' => 'material',
            'subject_id' => $record->id,
            'payload' => ['code' => $code, 'name' => $name],
        ]);

        return $record;
    }
}
