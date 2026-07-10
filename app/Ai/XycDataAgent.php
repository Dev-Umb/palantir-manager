<?php

namespace App\Ai;

use App\Ai\Tools\GetObjectRecordTool;
use App\Ai\Tools\ListVisibleObjectsTool;
use App\Ai\Tools\PublishHtmlArtifactTool;
use App\Ai\Tools\QueryObjectRecordsTool;
use App\Models\User;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

class XycDataAgent implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function __construct(private User $user) {}

    public function instructions(): Stringable|string
    {
        $today = now('Asia/Taipei')->toDateString();
        $metricGuidance = collect(config('ai_metrics', []))->map(function (array $metric) {
            $aliases = implode('、', $metric['aliases'] ?? []);

            return "- {$metric['label']}（同义词：{$aliases}）：{$metric['formula']}；单位 {$metric['unit']}；空值策略：{$metric['null_strategy']}。";
        })->implode("\n");

        return <<<PROMPT
你是鑫源昌智造中枢里的数据助手。你只能回答当前平台业务数据相关问题。

当前业务日期：{$today}（Asia/Taipei）。“本月”指当前自然月。

规则：
- 只能通过工具读取数据，不允许要求或编写 SQL。
- 工具返回无权限、不可见、无数据时，直接说明限制，不要猜测。
- 最终回答使用清晰的 Markdown，不输出原始 JSON，不输出可执行脚本。
- 调用工具前不要输出结论；工具完成后再给出最终回答。
- 用户明确要求 HTML 报告时，先完成数据查询，再调用 publish_html_artifact 发布静态结果。
- 汇总优先；用户明确要求明细时再列明细。
- 项目欠款优先使用 arrears；为空时工具会按 max(contract_amount - paid_amount, 0) 补算，回答中说明补算口径。
- 使用中文，结论先行，数字保留必要单位。

指标口径：
{$metricGuidance}
PROMPT;
    }

    /** @return Tool[] */
    public function tools(): iterable
    {
        return [
            new ListVisibleObjectsTool($this->user),
            new QueryObjectRecordsTool($this->user),
            new GetObjectRecordTool($this->user),
            app(PublishHtmlArtifactTool::class),
        ];
    }

    public function maxSteps(): int
    {
        return 6;
    }

    protected function maxConversationMessages(): int
    {
        return 24;
    }
}
