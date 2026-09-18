<?php

namespace App\Ai;

use App\Support\HubEvidenceRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class HubCollectorAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function providerOptions(Lab|string $provider): array
    {
        return ['reasoning' => ['effort' => config('procurement_hub.reasoning_effort')], 'store' => false];
    }

    public function maxTokens(): int
    {
        return (int) config('procurement_hub.max_output_tokens');
    }

    public function instructions(): string
    {
        return HubAgentRunner::BOUNDARY.' 只提取当前文档的一份公告。所有文本字段必须逐字来自原文，用 citations 给出包含该字段原值的原文短句，不改写日期或单位。找不到填空字符串并列入 missing。多包件混合总价不拆分或推测。title 用公告标题；amount 仅提取该包件明确的金额及单位。不是公告或涉及多个无法分离的包件价格时谨慎保留未知。candidate 不是 award。transaction 区分 sale/rental/unknown。amount_type 必须与原文价格阶段一致。';
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = [];
        foreach (HubEvidenceRules::FIELDS as $field) {
            $fields[$field] = $schema->string()->required();
        }

        return [...$fields,
            'is_notice' => $schema->boolean()->required(),
            'kind' => $schema->string()->enum(['notice', 'amendment', 'candidate', 'award', 'termination', 'intent'])->required(),
            'transaction' => $schema->string()->enum(['sale', 'rental', 'unknown'])->required(),
            'amount_type' => $schema->string()->enum(['budget', 'candidate', 'award', 'contract', 'unknown'])->required(),
            'citations' => $schema->array()->items($schema->object([
                'field' => $schema->string()->required(), 'quote' => $schema->string()->required(),
            ]))->required(),
            'missing' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
