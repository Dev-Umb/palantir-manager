<?php

namespace App\Ai\Tools;

use App\Integrations\Feishu\FeishuExportService;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

abstract class AbstractFeishuExportTool implements Tool
{
    public function __construct(protected User $user) {}

    abstract public function name(): string;

    abstract public function description(): Stringable|string;

    abstract protected function format(): string;

    public function handle(Request $request): Stringable|string
    {
        return json_encode(
            app(FeishuExportService::class)->export($this->user, $this->format(), $request->all()),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object' => $schema->string()->required()->description('Business object key, for example project.'),
            'select' => $schema->array()->items($schema->string())->required()->description('Columns to export. Select only fields needed by the user.'),
            'filters' => $schema->array()->items($schema->object([
                'field' => $schema->string()->required(),
                'operator' => $schema->string()->enum(['eq', 'contains', 'gt', 'gte', 'lt', 'lte', 'in', 'between', 'is_empty', 'not_empty'])->default('eq'),
                'value' => $schema->union(['string', 'number', 'boolean', 'null'])->nullable(),
                'values' => $schema->array()->items($schema->string())->nullable(),
            ]))->nullable(),
            'sort' => $schema->object([
                'field' => $schema->string()->required(),
                'direction' => $schema->string()->enum(['asc', 'desc'])->default('asc'),
            ])->nullable(),
            'limit' => $schema->integer()->nullable()->description('Maximum rows to export. Server-side limit applies.'),
            'title' => $schema->string()->nullable()->description('Optional Feishu file title.'),
        ];
    }
}
