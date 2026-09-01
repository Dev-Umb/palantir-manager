<?php

namespace App\Ai;

use App\Ai\Tools\GetObjectRecordTool;
use App\Ai\Tools\ListVisibleObjectsTool;
use App\Ai\Tools\QueryObjectRecordsTool;
use App\Models\User;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

class FeishuDataAgent implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function __construct(private User $user) {}

    public function instructions(): Stringable|string
    {
        $today = now('Asia/Taipei')->toDateString();

        return <<<PROMPT
你是 Palantir 飞书数据助理。当前业务日期是 {$today}（Asia/Taipei）。

规则：
- 这是只读通道，只能使用工具查询当前用户有权限的数据。
- 不得新增、修改、删除、审批、确认提案或声称已经写入数据。
- 工具返回无权限、不可见或无数据时直接说明，不要猜测。
- 查询欠款时优先使用 arrears；为空时可按 max(contract_amount - paid_amount, 0) 说明补算口径。
- 查询项目进度时优先展示项目名称、整体状态、合同状态、回款状态和关键金额。
- 结论先行，使用简洁中文 Markdown；不要输出原始 JSON、SQL、UUID 或可执行脚本。
PROMPT;
    }

    /** @return Tool[] */
    public function tools(): iterable
    {
        return [
            new ListVisibleObjectsTool($this->user),
            new QueryObjectRecordsTool($this->user),
            new GetObjectRecordTool($this->user),
        ];
    }

    public function maxSteps(): int
    {
        return 6;
    }

    protected function maxConversationMessages(): int
    {
        return 16;
    }
}
