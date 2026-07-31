<?php

namespace App\Ai\Tools;

use App\Ai\XycDataAccess;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class QueryObjectRecordsTool implements Tool
{
    public function __construct(private User $user) {}

    public function name(): string
    {
        return 'query_object_records';
    }

    public function description(): Stringable|string
    {
        return 'Query visible records for one business object with optional filters, grouping, metrics, sorting, and limit. For purchase requests, query material with id, name, and spec; unit belongs to requisition, not material.';
    }

    public function handle(Request $request): Stringable|string
    {
        $input = $request->all();
        $ignoredFields = $this->normalizeMaterialSelect($input);
        $result = app(XycDataAccess::class)->queryRecords($this->user, $input);

        if (($result['ok'] ?? false) && $ignoredFields !== []) {
            $result['warnings'] = [[
                'type' => 'ignored_select_field',
                'fields' => $ignoredFields,
                'message' => '物料主档不含 unit 字段，已忽略该查询字段；采购数量单位应写入 requisition.unit。',
            ]];
        }

        return json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object' => $schema->string()->required()->description('Business object key, for example project or material.'),
            'select' => $schema->array()->items($schema->string())->nullable()->description('Fields to return. Always select only fields needed for the answer.'),
            'filters' => $schema->array()->items($schema->object([
                'field' => $schema->string()->required(),
                'operator' => $schema->string()->enum(['eq', 'contains', 'gt', 'gte', 'lt', 'lte', 'in', 'between', 'is_empty', 'not_empty'])->default('eq'),
                'value' => $schema->union(['string', 'number', 'boolean', 'null'])->nullable(),
                'values' => $schema->array()->items($schema->string())->nullable(),
            ]))->nullable(),
            'group_by' => $schema->string()->nullable(),
            'metrics' => $schema->array()->items($schema->object([
                'op' => $schema->string()->enum(['count', 'sum', 'avg', 'min', 'max'])->required(),
                'field' => $schema->string()->nullable(),
                'label' => $schema->string()->nullable(),
            ]))->nullable(),
            'sort' => $schema->object([
                'field' => $schema->string()->required(),
                'direction' => $schema->string()->enum(['asc', 'desc'])->default('asc'),
            ])->nullable(),
            'limit' => $schema->integer()->nullable()->description('Maximum rows to return, capped at 200.'),
        ];
    }

    private function normalizeMaterialSelect(array &$input): array
    {
        if (($input['object'] ?? null) !== 'material' || ! is_array($input['select'] ?? null)) {
            return [];
        }

        $ignoredFields = collect($input['select'])
            ->filter(fn (mixed $field): bool => $field === 'unit')
            ->values()
            ->all();

        if ($ignoredFields === []) {
            return [];
        }

        $input['select'] = collect($input['select'])
            ->reject(fn (mixed $field): bool => $field === 'unit')
            ->values()
            ->all();

        if ($input['select'] === []) {
            $input['select'] = ['id', 'name', 'spec'];
        }

        return $ignoredFields;
    }
}
