<?php

namespace App\Ai;

use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\ObjectRelations;
use App\Support\ProjectVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class XycDataAccess
{
    public function __construct(
        private ObjectRelations $relations,
        private ProjectVisibility $projectVisibility,
    ) {}

    public function visibleObjects(User $user): array
    {
        $objects = BusinessObject::orderBy('sort_order')->get()
            ->filter(fn (BusinessObject $object) => $user->canDo("object.{$object->key}.view"))
            ->values()
            ->map(fn (BusinessObject $object) => [
                'key' => $object->key,
                'label' => $object->label,
                'group' => $object->group,
                'fields' => collect($object->fields)->map(fn (array $field) => [
                    'key' => $field['key'],
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'target' => $field['target'] ?? null,
                ])->all(),
            ])
            ->all();

        $this->audit($user, 'ai.tool.list_visible_objects', 'business_objects', null, [
            'object_count' => count($objects),
        ]);

        return $objects;
    }

    public function queryRecords(User $user, array $input): array
    {
        $startedAt = microtime(true);
        $object = $this->objectForUser($user, (string) ($input['object'] ?? ''));
        if (! $object) {
            $this->audit($user, 'ai.tool.query_records.denied', 'unknown', null, [
                'requested_object' => (string) ($input['object'] ?? ''),
            ]);

            return $this->denied('forbidden', '无权访问该业务对象，或该业务对象不存在。');
        }

        if ($invalid = $this->invalidInputField($object, $input)) {
            return $this->denied('invalid_field', "字段 {$invalid} 不存在或不可查询。");
        }

        $query = $this->recordsQuery($user, $object);
        if ($this->isPostgres($query)) {
            $query = $this->applyPostgresFilters($query, $object, $input['filters'] ?? []);
            $result = filled($input['group_by'] ?? null)
                ? $this->aggregatePostgres($object, $query, (string) $input['group_by'], $input)
                : $this->listPostgres($object, $query, $input);
        } else {
            $records = $query->latest()->get();
            $records = $this->filterRecords($records, $input['filters'] ?? []);
            $result = filled($input['group_by'] ?? null)
                ? $this->aggregateRecords($object, $records, (string) $input['group_by'], $input)
                : $this->listRecords($object, $records, $input);
        }

        $this->audit($user, 'ai.tool.query_records', $object->key, null, [
            'filter_count' => count($input['filters'] ?? []),
            'group_by' => $input['group_by'] ?? null,
            'record_count' => $result['record_count'],
            'returned_count' => count($result['rows']),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }

    public function getRecord(User $user, string $objectKey, string $id): array
    {
        $object = $this->objectForUser($user, $objectKey);
        if (! $object) {
            return $this->denied('forbidden', '无权访问该业务对象，或该业务对象不存在。');
        }

        $record = $this->recordsQuery($user, $object)->whereKey($id)->first();
        if (! $record) {
            return $this->denied('not_found', '记录不存在或当前用户不可见。');
        }

        $this->relations->preloadLabels(collect([$record]));
        $row = $this->relations->formatRecord($record);

        $this->audit($user, 'ai.tool.get_record', $object->key, $record->id, [
            'record_count' => 1,
        ]);

        return [
            'ok' => true,
            'record' => $row,
            'sources' => [$this->source($object, 1)],
        ];
    }

    private function objectForUser(User $user, string $key): ?BusinessObject
    {
        if ($key === '') {
            return null;
        }

        $object = BusinessObject::where('key', $key)->first();

        return $object && $user->canDo("object.{$object->key}.view") ? $object : null;
    }

    private function recordsQuery(User $user, BusinessObject $object): Builder|Relation
    {
        $query = $object->records()->with('businessObject');

        if ($object->key === 'project') {
            $this->projectVisibility->scope($query, $user);
        }

        if ($object->key === 'requisition' && ! $user->canDo('object.requisition.update')) {
            $query->where('created_by', $user->id);
        }

        return $query;
    }

    private function isPostgres(Builder|Relation $query): bool
    {
        return $query->getModel()->getConnection()->getDriverName() === 'pgsql';
    }

    private function applyPostgresFilters(Builder|Relation $query, BusinessObject $object, array $filters): Builder|Relation
    {
        foreach ($filters as $filter) {
            if (! is_array($filter) || blank($filter['field'] ?? null)) {
                continue;
            }

            $field = (string) $filter['field'];
            $operator = $filter['operator'] ?? 'eq';
            $textExpression = $this->postgresTextExpression($field);
            $valueExpression = $this->fieldType($object, $field) === 'number'
                ? $this->postgresNumericExpression($field)
                : $textExpression;
            $value = $filter['value'] ?? null;

            match ($operator) {
                'contains' => $query->whereRaw("LOWER(COALESCE({$textExpression}, '')) LIKE LOWER(?)", ['%'.$value.'%']),
                'gt' => $query->whereRaw("{$valueExpression} > ?", [$value]),
                'gte' => $query->whereRaw("{$valueExpression} >= ?", [$value]),
                'lt' => $query->whereRaw("{$valueExpression} < ?", [$value]),
                'lte' => $query->whereRaw("{$valueExpression} <= ?", [$value]),
                'between' => $query->whereRaw("{$valueExpression} BETWEEN ? AND ?", [
                    ($filter['values'] ?? [])[0] ?? null,
                    ($filter['values'] ?? [])[1] ?? null,
                ]),
                'is_empty' => $query->whereRaw("({$textExpression} IS NULL OR {$textExpression} = '')"),
                'not_empty' => $query->whereRaw("({$textExpression} IS NOT NULL AND {$textExpression} <> '')"),
                'in' => $query->where(function (Builder $nested) use ($valueExpression, $filter) {
                    foreach ((array) ($filter['values'] ?? []) as $index => $item) {
                        $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                        $nested->{$method}("{$valueExpression} = ?", [$item]);
                    }
                }),
                default => $query->whereRaw("{$valueExpression} = ?", [$value]),
            };
        }

        return $query;
    }

    private function listPostgres(BusinessObject $object, Builder|Relation $query, array $input): array
    {
        $recordCount = (clone $query)->count();
        $sort = $input['sort'] ?? null;
        if (is_array($sort) && filled($sort['field'] ?? null)) {
            $field = (string) $sort['field'];
            $expression = $this->fieldType($object, $field) === 'number'
                ? $this->postgresNumericExpression($field)
                : $this->postgresTextExpression($field);
            $direction = ($sort['direction'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
            $query->reorder()->orderByRaw("{$expression} {$direction} NULLS LAST");
        } else {
            $query->latest();
        }

        return $this->listRecords(
            $object,
            $query->limit($this->limit($input['limit'] ?? null))->get(),
            $input,
            $recordCount,
            true,
        );
    }

    private function aggregatePostgres(BusinessObject $object, Builder|Relation $query, string $groupBy, array $input): array
    {
        $metrics = collect($input['metrics'] ?? [['op' => 'count', 'label' => '数量']])->values();
        $recordCount = (clone $query)->count();
        $groupExpression = $this->postgresTextExpression($groupBy);
        $normalizedGroup = "COALESCE(NULLIF({$groupExpression}, ''), '未填写')";
        $aggregateQuery = $query->reorder()->selectRaw("{$normalizedGroup} AS ai_group");

        foreach ($metrics as $index => $metric) {
            $op = is_array($metric) ? ($metric['op'] ?? 'count') : 'count';
            $field = is_array($metric) ? (string) ($metric['field'] ?? '') : '';
            $numericExpression = $field !== '' ? $this->postgresNumericExpression($field) : null;
            $aggregate = match ($op) {
                'sum' => "SUM({$numericExpression})",
                'avg' => "AVG({$numericExpression})",
                'min' => "MIN({$numericExpression})",
                'max' => "MAX({$numericExpression})",
                default => 'COUNT(*)',
            };
            $aggregateQuery->selectRaw("{$aggregate} AS metric_{$index}");

            if ($numericExpression !== null && $op !== 'count') {
                $aggregateQuery->selectRaw("COUNT(*) FILTER (WHERE {$numericExpression} IS NULL) AS missing_{$index}");
                if ($field === 'arrears') {
                    $explicit = $this->postgresJsonText('arrears');
                    $aggregateQuery->selectRaw(
                        "COUNT(*) FILTER (WHERE ({$explicit} IS NULL OR {$explicit} = '') AND {$numericExpression} IS NOT NULL) AS derived_{$index}",
                    );
                }
            }
        }

        $aggregateQuery->groupByRaw($normalizedGroup);
        $sort = $input['sort'] ?? null;
        $sortIndex = is_array($sort)
            ? $metrics->search(fn ($metric) => is_array($metric) && ($metric['label'] ?? null) === ($sort['field'] ?? null))
            : false;
        $direction = is_array($sort) && ($sort['direction'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        if ($sortIndex !== false) {
            $aggregateQuery->orderByRaw("metric_{$sortIndex} {$direction} NULLS LAST");
        } else {
            $aggregateQuery->orderByRaw("ai_group {$direction}");
        }

        $results = $aggregateQuery->limit($this->limit($input['limit'] ?? null))->get();
        $rows = $results->map(function (ObjectRecord $record) use ($metrics) {
            $row = ['group' => $record->getAttribute('ai_group')];
            foreach ($metrics as $index => $metric) {
                $label = is_array($metric) ? ($metric['label'] ?? ($metric['field'] ?? '数量')) : '数量';
                $value = $record->getAttribute("metric_{$index}");
                $row[$label] = $value === null
                    ? null
                    : (($metric['op'] ?? 'count') === 'count' ? (int) $value : round((float) $value, 2));
            }

            return $row;
        })->all();

        return [
            'ok' => true,
            'object' => ['key' => $object->key, 'label' => $object->label],
            'group_by' => $groupBy,
            'fields' => collect($this->fieldDefinitions($object))->where('key', $groupBy)->values()->all(),
            'record_count' => $recordCount,
            'rows' => $rows,
            'sources' => [$this->source($object, $recordCount)],
            'data_quality' => $this->postgresAggregateDataQuality($results, $metrics),
        ];
    }

    private function postgresAggregateDataQuality(Collection $results, Collection $metrics): array
    {
        return $metrics->flatMap(function ($metric, int $index) use ($results) {
            if (! is_array($metric) || blank($metric['field'] ?? null) || ($metric['op'] ?? 'count') === 'count') {
                return [];
            }

            $field = (string) $metric['field'];
            $missing = $results->sum(fn (ObjectRecord $record) => (int) $record->getAttribute("missing_{$index}"));
            $derived = $field === 'arrears'
                ? $results->sum(fn (ObjectRecord $record) => (int) $record->getAttribute("derived_{$index}"))
                : 0;

            return collect([
                $missing > 0 ? [
                    'type' => 'missing',
                    'field' => $field,
                    'message' => "{$missing} 条记录的 {$field} 缺失，聚合时保留为空且未按 0 计算。",
                ] : null,
                $derived > 0 ? [
                    'type' => 'derived',
                    'field' => $field,
                    'message' => "{$derived} 条记录的欠款按合同金额减回款金额补算。",
                ] : null,
            ])->filter();
        })->values()->all();
    }

    private function postgresTextExpression(string $field): string
    {
        return match ($field) {
            'id', 'code', 'title' => 'object_records."'.$field.'"',
            'created_at', 'updated_at' => 'CAST(object_records."'.$field.'" AS TEXT)',
            default => $this->postgresJsonText($field),
        };
    }

    private function postgresJsonText(string $field): string
    {
        $safe = str_replace("'", "''", $field);

        return "object_records.payload->>'{$safe}'";
    }

    private function postgresNumericExpression(string $field): string
    {
        if ($field === 'arrears') {
            $arrears = $this->postgresJsonText('arrears');
            $contract = $this->postgresJsonText('contract_amount');
            $paid = $this->postgresJsonText('paid_amount');
            $arrearsNumber = $this->postgresSafeNumeric($arrears);
            $contractNumber = $this->postgresSafeNumeric($contract);
            $paidNumber = $this->postgresSafeNumeric($paid);

            return "CASE WHEN {$arrearsNumber} IS NOT NULL THEN {$arrearsNumber} "
                ."WHEN {$contractNumber} IS NOT NULL OR {$paidNumber} IS NOT NULL "
                ."THEN GREATEST(COALESCE({$contractNumber}, 0) - COALESCE({$paidNumber}, 0), 0) ELSE NULL END";
        }

        return $this->postgresSafeNumeric($this->postgresTextExpression($field));
    }

    private function postgresSafeNumeric(string $expression): string
    {
        return "CASE WHEN ({$expression}) ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN ({$expression})::numeric END";
    }

    private function fieldType(BusinessObject $object, string $field): string
    {
        return (string) (collect($this->fieldDefinitions($object))->firstWhere('key', $field)['type'] ?? 'text');
    }

    private function filterRecords(Collection $records, array $filters): Collection
    {
        foreach ($filters as $filter) {
            if (! is_array($filter) || blank($filter['field'] ?? null)) {
                continue;
            }

            $records = $records->filter(fn (ObjectRecord $record) => $this->matchesFilter($record, $filter));
        }

        return $records->values();
    }

    private function matchesFilter(ObjectRecord $record, array $filter): bool
    {
        $actual = $this->recordValue($record, (string) $filter['field']);
        $operator = $filter['operator'] ?? 'eq';
        $expected = $filter['value'] ?? null;

        return match ($operator) {
            'contains' => str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'gt' => (float) $actual > (float) $expected,
            'gte' => (float) $actual >= (float) $expected,
            'lt' => (float) $actual < (float) $expected,
            'lte' => (float) $actual <= (float) $expected,
            'in' => in_array($actual, (array) ($filter['values'] ?? []), true),
            'between' => $actual >= (($filter['values'] ?? [])[0] ?? null)
                && $actual <= (($filter['values'] ?? [])[1] ?? null),
            'is_empty' => $actual === null || $actual === '',
            'not_empty' => $actual !== null && $actual !== '',
            default => (string) $actual === (string) $expected,
        };
    }

    private function listRecords(
        BusinessObject $object,
        Collection $records,
        array $input,
        ?int $recordCount = null,
        bool $alreadyLimited = false,
    ): array {
        if (! $alreadyLimited) {
            $records = $this->sortRecords($records, $input['sort'] ?? null);
        }
        $recordCount ??= $records->count();
        $rows = $alreadyLimited
            ? $records->values()
            : $records->take($this->limit($input['limit'] ?? null))->values();
        $selected = $this->selectedFields($object, $input['select'] ?? null);

        $this->relations->preloadLabels($rows);

        $derivedArrears = false;
        $projectedRows = $rows->map(function (ObjectRecord $record) use ($object, $selected, &$derivedArrears) {
            $formatted = $this->relations->formatRecord($record);

            return collect($selected)->mapWithKeys(function (array $field) use ($record, $formatted, $object, &$derivedArrears) {
                $key = $field['key'];
                if ($object->key === 'project' && $key === 'arrears' && blank($record->payload['arrears'] ?? null)) {
                    $derivedArrears = filled($record->payload['contract_amount'] ?? null)
                        || filled($record->payload['paid_amount'] ?? null);
                }

                $value = $object->key === 'project' && $key === 'arrears'
                    ? $this->recordValue($record, $key)
                    : (array_key_exists($key, $formatted['display'])
                        ? $formatted['display'][$key]
                        : $this->recordValue($record, $key));

                if (($field['type'] ?? null) === 'number' && is_numeric($value)) {
                    $value = (float) $value;
                }

                return [$key => $value];
            })->all();
        })->all();

        return [
            'ok' => true,
            'object' => ['key' => $object->key, 'label' => $object->label],
            'fields' => $selected,
            'record_count' => $recordCount,
            'rows' => $projectedRows,
            'sources' => [$this->source($object, $recordCount)],
            'data_quality' => $derivedArrears ? [[
                'type' => 'derived',
                'field' => 'arrears',
                'message' => '部分项目欠款为空，已按合同金额减回款金额补算。',
            ]] : [],
        ];
    }

    private function aggregateRecords(BusinessObject $object, Collection $records, string $groupBy, array $input): array
    {
        $metrics = $input['metrics'] ?? [['op' => 'count', 'label' => '数量']];
        $rows = $records
            ->groupBy(fn (ObjectRecord $record) => (string) ($this->recordValue($record, $groupBy) ?: '未填写'))
            ->map(function (Collection $group, string $value) use ($metrics) {
                $row = ['group' => $value];

                foreach ($metrics as $metric) {
                    if (! is_array($metric)) {
                        continue;
                    }

                    $op = $metric['op'] ?? 'count';
                    $field = (string) ($metric['field'] ?? '');
                    $label = $metric['label'] ?? ($field ? "{$op}_{$field}" : $op);
                    $values = $field === ''
                        ? collect()
                        : $group->map(fn (ObjectRecord $record) => $this->recordValue($record, $field))
                            ->filter(fn ($value) => is_numeric($value))
                            ->map(fn ($value) => (float) $value);

                    $row[$label] = match ($op) {
                        'sum' => $values->isEmpty() ? null : round($values->sum(), 2),
                        'avg' => $values->isEmpty() ? null : round($values->avg(), 2),
                        'min' => $values->isEmpty() ? null : $values->min(),
                        'max' => $values->isEmpty() ? null : $values->max(),
                        default => $group->count(),
                    };
                }

                return $row;
            })
            ->values();

        $sort = $input['sort'] ?? null;
        if (is_array($sort) && filled($sort['field'] ?? null)) {
            $rows = $rows->sortBy(
                fn (array $row) => $row[$sort['field']] ?? null,
                SORT_REGULAR,
                ($sort['direction'] ?? 'asc') === 'desc',
            )->values();
        }

        $dataQuality = collect($metrics)
            ->filter(fn ($metric) => is_array($metric) && filled($metric['field'] ?? null) && ($metric['op'] ?? 'count') !== 'count')
            ->pluck('field')
            ->unique()
            ->flatMap(function (string $field) use ($records) {
                $missing = $records->filter(fn (ObjectRecord $record) => ! is_numeric($this->recordValue($record, $field)))->count();
                $derived = $field === 'arrears'
                    ? $records->filter(fn (ObjectRecord $record) => blank($record->payload['arrears'] ?? null)
                        && is_numeric($this->recordValue($record, 'arrears')))->count()
                    : 0;

                return collect([
                    $missing > 0 ? [
                        'type' => 'missing',
                        'field' => $field,
                        'message' => "{$missing} 条记录的 {$field} 缺失，聚合时保留为空且未按 0 计算。",
                    ] : null,
                    $derived > 0 ? [
                        'type' => 'derived',
                        'field' => $field,
                        'message' => "{$derived} 条记录的欠款按合同金额减回款金额补算。",
                    ] : null,
                ])->filter();
            })
            ->values()
            ->all();

        return [
            'ok' => true,
            'object' => ['key' => $object->key, 'label' => $object->label],
            'group_by' => $groupBy,
            'fields' => collect($this->fieldDefinitions($object))->where('key', $groupBy)->values()->all(),
            'record_count' => $records->count(),
            'rows' => $rows->take($this->limit($input['limit'] ?? null))->all(),
            'sources' => [$this->source($object, $records->count())],
            'data_quality' => $dataQuality,
        ];
    }

    private function sortRecords(Collection $records, mixed $sort): Collection
    {
        if (! is_array($sort) || blank($sort['field'] ?? null)) {
            return $records->values();
        }

        return $records->sortBy(
            fn (ObjectRecord $record) => $this->recordValue($record, (string) $sort['field']),
            SORT_REGULAR,
            ($sort['direction'] ?? 'asc') === 'desc',
        )->values();
    }

    private function recordValue(ObjectRecord $record, string $field): mixed
    {
        return match ($field) {
            'id' => $record->id,
            'code' => $record->code,
            'title' => $record->title,
            'created_at' => $record->created_at?->toDateString(),
            'updated_at' => $record->updated_at?->toDateString(),
            'arrears' => $this->projectArrears($record),
            default => $record->payload[$field] ?? null,
        };
    }

    private function projectArrears(ObjectRecord $record): mixed
    {
        $arrears = $record->payload['arrears'] ?? null;
        if ($arrears !== null && $arrears !== '') {
            return $arrears;
        }

        $contract = $record->payload['contract_amount'] ?? null;
        $paid = $record->payload['paid_amount'] ?? null;
        if (! is_numeric($contract) && ! is_numeric($paid)) {
            return null;
        }

        return (float) max((float) $contract - (float) $paid, 0);
    }

    private function invalidInputField(BusinessObject $object, array $input): ?string
    {
        $allowed = collect($this->fieldDefinitions($object))->pluck('key');
        $requested = collect($input['select'] ?? [])
            ->merge(collect($input['filters'] ?? [])->pluck('field'))
            ->merge(collect($input['metrics'] ?? [])->pluck('field'))
            ->merge([$input['group_by'] ?? null])
            ->filter();

        foreach ($requested as $field) {
            if (! is_string($field) || ! $allowed->contains($field)) {
                return is_scalar($field) ? (string) $field : 'unknown';
            }
        }

        $sortField = $input['sort']['field'] ?? null;
        $metricLabels = collect($input['metrics'] ?? [])->pluck('label')->filter();
        if ($sortField && ! $allowed->contains($sortField) && ! $metricLabels->contains($sortField)) {
            return (string) $sortField;
        }

        return null;
    }

    private function selectedFields(BusinessObject $object, mixed $requested): array
    {
        $definitions = collect($this->fieldDefinitions($object))->keyBy('key');
        $keys = is_array($requested) && $requested !== []
            ? collect($requested)
            : collect(['code', 'title'])->merge(
                collect($object->fields)->reject(fn (array $field) => ($field['type'] ?? null) === 'file')->pluck('key')->take(8)
            );

        return $keys->unique()->map(fn (string $key) => $definitions[$key])->values()->all();
    }

    private function fieldDefinitions(BusinessObject $object): array
    {
        return [
            ['key' => 'id', 'label' => '记录 ID', 'type' => 'text'],
            ['key' => 'code', 'label' => '编号', 'type' => 'text'],
            ['key' => 'title', 'label' => '名称', 'type' => 'text'],
            ['key' => 'created_at', 'label' => '创建日期', 'type' => 'date'],
            ['key' => 'updated_at', 'label' => '更新日期', 'type' => 'date'],
            ...collect($object->fields)->map(fn (array $field) => [
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'target' => $field['target'] ?? null,
            ])->all(),
        ];
    }

    private function limit(mixed $limit): int
    {
        return max(1, min((int) ($limit ?: 50), 200));
    }

    private function source(BusinessObject $object, int $count): array
    {
        return [
            'object_key' => $object->key,
            'object_label' => $object->label,
            'record_count' => $count,
        ];
    }

    private function denied(string $error, string $message): array
    {
        return ['ok' => false, 'error' => $error, 'message' => $message, 'rows' => [], 'sources' => []];
    }

    private function audit(User $user, string $action, string $subjectType, ?string $subjectId, array $payload): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $payload,
        ]);
    }
}
