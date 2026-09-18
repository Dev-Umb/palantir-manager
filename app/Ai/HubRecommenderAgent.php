<?php

namespace App\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class HubRecommenderAgent implements Agent, HasProviderOptions, HasStructuredOutput
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
        return HubAgentRunner::BOUNDARY.' 输出一份最多1000字的跟进报告。claims 必须包含 decision（值得、有条件或仅作历史参考）、match（主档相似点）、qualification（资格缺口）、counterexample（未中反例，没有则说明未知）、window（报名与投标窗口分别按 as_of 判断）、action（下一步具体建议）；可增加 price。外部事实的 quotes 只选择 evidence.passages 中真实 quote_key；使用项目主档时，project_references 只选择 projects.fields 中真实 project_key，正文使用真实项目名称，不能写临时编号。匹配目标与内部项目时同时引用目标原文片段与内部字段。qualification 只摘录两项最关键资格条件并说明主档证明缺口，完整要求以原文及附件为准，不复述全部条件。资格条件不得扩大，保留“或、至少、近若干年内”等限定词；工程总工期不是产品供货期限。材料未提供一律待核实，不是已满足或不满足。主档存在不证明中标或与目标集团合作；无记录不等于从未合作。只依据项目主档不足以确认企业资质，本版 score=0、verdict=insufficient，正文仍可给有条件的跟进建议。报名已截止而投标未截止，只对已报名者给条件性建议。行动项明确标为建议，勿把建议的材料清单写成公告强制要求。历史价格区分预算、候选报价、成交金额及租赁总额，不猜单价。correction_issues 如有，修正所指出的事实错误，不重复旧错误。';
    }

    public function schema(JsonSchema $schema): array
    {
        return [...HubAgentRunner::reportSchema($schema),
            'score' => $schema->integer()->min(0)->max(100)->required(),
            'verdict' => $schema->string()->enum(['suitable', 'conditional', 'unsuitable', 'insufficient'])->required(),
            'project_references' => $schema->array()->max(10)->items($schema->object(['project_key' => $schema->string()->required()]))->required(),
        ];
    }
}
