<?php

namespace App\Actions;

use App\Models\ObjectRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class BuildFilteredRecordSubtotal
{
    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array{label: string, values: array<string, float>}|null
     */
    public function handle(Builder|Relation $query, array $fields): ?array
    {
        $numericFields = collect($fields)
            ->filter(fn (array $field): bool => ($field['type'] ?? null) === 'number')
            ->keyBy('key');
        if ($numericFields->isEmpty()) {
            return null;
        }

        $totals = array_fill_keys($numericFields->keys()->all(), 0.0);
        $records = (clone $query)
            ->withoutEagerLoads()
            ->select(['object_records.payload'])
            ->cursor();

        /** @var ObjectRecord $record */
        foreach ($records as $record) {
            $payload = $record->payload ?? [];
            foreach ($numericFields as $key => $field) {
                if (($field['scope'] ?? null) === 'item') {
                    foreach ($this->items($payload) as $item) {
                        $totals[$key] += $this->number($item[$key] ?? null);
                    }

                    continue;
                }

                $totals[$key] += $this->number($payload[$key] ?? null);
            }
        }

        return [
            'label' => '小计',
            'values' => collect($totals)
                ->map(fn (float $total): float => round($total, 10))
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function items(array $payload): array
    {
        return collect($payload['items'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values()
            ->all();
    }

    private function number(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : 0.0;
    }
}
