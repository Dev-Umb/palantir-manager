<?php

namespace App\Ai;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AiArtifactFactory
{
    public function fromToolResult(string $toolName, array $payload, array $arguments = []): array
    {
        if (! ($payload['ok'] ?? false)) {
            return ['artifacts' => [], 'sources' => [], 'data_quality' => []];
        }

        if ($toolName === 'publish_html_artifact' && is_array($payload['artifact'] ?? null)) {
            return [
                'artifacts' => [$payload['artifact']],
                'sources' => [],
                'data_quality' => [],
            ];
        }

        if (in_array($toolName, [
            'prepare_object_record_create',
            'prepare_object_record_update',
            'present_user_choice',
            'present_user_form',
        ], true)
            && is_array($payload['artifact'] ?? null)) {
            return [
                'artifacts' => [$payload['artifact']],
                'sources' => [],
                'data_quality' => [],
            ];
        }

        if ($toolName !== 'query_object_records') {
            return [
                'artifacts' => [],
                'sources' => $payload['sources'] ?? [],
                'data_quality' => $payload['data_quality'] ?? [],
            ];
        }

        $rows = collect($payload['rows'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => Arr::except($row, ['id', 'payload', 'display', 'group_by']))
            ->values()
            ->all();
        if ($rows === []) {
            return [
                'artifacts' => [],
                'sources' => $payload['sources'] ?? [],
                'data_quality' => $payload['data_quality'] ?? [],
            ];
        }

        $fields = collect($payload['fields'] ?? [])->keyBy('key');
        $columns = collect(array_keys($rows[0]))->map(fn (string $key) => [
            'key' => $key,
            'label' => $key === 'group'
                ? ($fields[$payload['group_by'] ?? '']['label'] ?? '分组')
                : ($fields[$key]['label'] ?? $key),
            'type' => $fields[$key]['type'] ?? $this->inferType($rows, $key),
        ])->values()->all();
        $title = ($payload['object']['label'] ?? '数据').'分析';

        $artifacts = [[
            'id' => (string) Str::uuid7(),
            'type' => 'table',
            'title' => $title,
            'revision' => 1,
            'data' => ['columns' => $columns, 'rows' => $rows],
        ]];

        if ($chart = $this->chart($rows, $columns, $arguments, $title)) {
            $artifacts[] = [
                'id' => (string) Str::uuid7(),
                'type' => 'chart',
                'title' => $title,
                'revision' => 1,
                'data' => $chart,
            ];
        }

        return [
            'artifacts' => $artifacts,
            'sources' => $payload['sources'] ?? [],
            'data_quality' => $payload['data_quality'] ?? [],
        ];
    }

    private function chart(array $rows, array $columns, array $arguments, string $title): ?array
    {
        $numeric = collect($columns)->pluck('key')->filter(
            fn (string $key) => collect($rows)->contains(fn (array $row) => is_numeric($row[$key] ?? null))
        );
        $preferredY = $arguments['sort']['field'] ?? null;
        $y = is_string($preferredY) && $numeric->contains($preferredY) ? $preferredY : $numeric->first();
        $x = collect($columns)->pluck('key')->first(fn (string $key) => $key !== $y && ! $numeric->contains($key));

        if (! $x || ! $y) {
            return null;
        }

        return [
            'type' => 'bar',
            'title' => $title,
            'x' => $x,
            'y' => $y,
            'rows' => $rows,
        ];
    }

    private function inferType(array $rows, string $key): string
    {
        return collect($rows)->contains(fn (array $row) => is_numeric($row[$key] ?? null)) ? 'number' : 'text';
    }
}
