<?php

namespace App\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class HubSupervisorAgent implements Agent, HasProviderOptions, HasStructuredOutput
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

    public function __construct(public string $mode = 'audit') {}

    public function instructions(): string
    {
        return HubAgentRunner::BOUNDARY.($this->mode === 'plan'
            ? ' 作为统筹，拆分给定研究目标并指出样本缺口。仅从 allowed_evidence_ids 选择证据；补查只可提出 source_ids 内的已批准来源及关键词。不要写分析结论。'
            : ' 作为独立审计员重新核对原文、程序统计、项目主档与分析/推荐。前序报告已由程序把 quote_key 解析为 evidence_ids 和真实 quotes，这是可信的格式转换，不得因缺少 quote_key 拒绝。当前 evidence 中的片段编号只用于本次阅读，不与前次片段编号进行机械比较。issues 只列阻止发布的事实错误或缺乏依据的断言；已经明确说明的未知、样本限制和标明为建议的准备事项，本身不是阻止发布的理由。资料不足但没有无依据断言的报告可以 pass，不会因此认定资格满足；只要材料不充分，文字建议应保持条件性，评分应为0。不要为了润色或重复已声明的限制要求修正。逐条检查结论是否有支持证据、引用是否实质支持、数值口径、包件/轮次、集团关系、我方能力、过期条件和反例。不得相信工作 Agent 的自我评分。资料缺失要明确。只输出审计；不能重写推荐。任何无依据结论都不能 pass；可修正为revise，无法支撑为insufficient。');
    }

    public function schema(JsonSchema $schema): array
    {
        if ($this->mode === 'plan') {
            return [
                'evidence_ids' => $schema->array()->items($schema->integer())->required(),
                'tasks' => $schema->array()->items($schema->string())->max(8)->required(),
                'gaps' => $schema->array()->items($schema->string())->required(),
                'source_ids' => $schema->array()->items($schema->integer())->max(3)->required(),
            ];
        }

        return [
            'decision' => $schema->string()->enum(['pass', 'revise', 'insufficient'])->required(),
            'issues' => $schema->array()->items($schema->string())->required(),
            'checked_evidence_ids' => $schema->array()->items($schema->integer())->required(),
        ];
    }
}
