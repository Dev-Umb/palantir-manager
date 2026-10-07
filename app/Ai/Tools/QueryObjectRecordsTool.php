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
        return 'Query visible records for one business object with optional filters, grouping, metrics, sorting, and limit. For totals, supply metrics without group_by to aggregate every matching visible record. Project debt uses unpaid_amount > 0; never derive arrears. Resolve salesperson names to account IDs first.';
    }

    public function handle(Request $request): Stringable|string
    {
        $input = $request->all();
        $result = app(XycDataAccess::class)->queryRecords($this->user, $input);

        return json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object' => $schema->string()->required()->description('Business object key, for example project or customer.'),
            'select' => $schema->array()->items($schema->string())->nullable()->description('Fields to return. Always select only fields needed for the answer.'),
            'filters' => $schema->array()->items($schema->object([
                'field' => $schema->string()->required(),
                'operator' => $schema->string()->enum(['eq', 'contains', 'gt', 'gte', 'lt', 'lte', 'in', 'between', 'is_empty', 'not_empty'])->default('eq'),
                'value' => $schema->union(['string', 'number', 'boolean', 'null'])->nullable(),
                'values' => $schema->array()->items($schema->string())->nullable(),
            ]))->nullable(),
            'group_by' => $schema->string()->nullable()->description('Omit for an overall total; metrics then aggregate all matching visible records.'),
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
}
