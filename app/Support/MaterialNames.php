<?php

namespace App\Support;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use Illuminate\Validation\ValidationException;

class MaterialNames
{
    public function normalize(string $value): string
    {
        $value = mb_convert_kana($value, 'asKV', 'UTF-8');
        $value = preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    public function canonical(string $value): string
    {
        return mb_strtolower($this->normalize($value), 'UTF-8');
    }

    public function normalizeAndGuardUnique(
        BusinessObject $object,
        array $payload,
        ?string $exceptRecordId = null,
    ): array {
        if ($object->key !== 'material') {
            return $payload;
        }

        $name = $this->normalize((string) ($payload['name'] ?? ''));
        $payload['name'] = $name;
        if ($name === '') {
            return $payload;
        }

        BusinessObject::query()->whereKey($object->id)->lockForUpdate()->firstOrFail();
        $canonical = $this->canonical($name);
        $duplicate = $object->records()
            ->when($exceptRecordId, fn ($query) => $query->whereKeyNot($exceptRecordId))
            ->get(['id', 'title', 'payload'])
            ->first(fn (ObjectRecord $record) => $this->canonical(
                (string) (($record->payload['name'] ?? '') ?: $record->title),
            ) === $canonical);

        if ($duplicate) {
            throw ValidationException::withMessages([
                'payload.name' => '物资名称已存在，请直接使用现有材料主档。',
            ]);
        }

        return $payload;
    }
}
