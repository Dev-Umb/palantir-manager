<?php

namespace App\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class HubAnalystAgent implements Agent, HasProviderOptions, HasStructuredOutput
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
        return HubAgentRunner::BOUNDARY.' 全文控制在1000个中文字以内。claims 的 section 使用 price 或 market。至少一条 price 说明本次历史价格参考，逐一标注预算/候选报价/成交金额/租赁总额，金额未提取但原文有金额时可以逐字引用，禁止推算单位价格。写出税运、规格、数量、租期是否完整，以及与目标的可比性。没有成交金额必须明确样本不足并提出补查对应成交公示或结算口径，不用预算冒充成交。 facts._conflicts 中的跨来源冲突必须列入 limitations，不可自行选一个价格。研究采购主体、集团、类似产品、历史供应商和价格。价格只解释系统 statistics 中已计算的可比样本，不能自行将预算或租赁总价换算成成交单价。group_confirmed=false 的归属只是待核实信息。sample_count 表示已取得样本，不代表市场全量。输出 claims 中每个事实均选择原文片段 quote_key，limitations 包含公开程度与样本限制。';
    }

    public function schema(JsonSchema $schema): array
    {
        return HubAgentRunner::reportSchema($schema, ['price', 'market']);
    }
}
