<?php

namespace App\Ai;

use App\Actions\BuildAiUpdateProposal;
use App\Actions\BuildAiWriteProposal;
use App\Ai\Tools\GetObjectRecordTool;
use App\Ai\Tools\ListVisibleObjectsTool;
use App\Ai\Tools\PrepareObjectRecordCreateTool;
use App\Ai\Tools\PrepareObjectRecordUpdateTool;
use App\Ai\Tools\PresentUserChoiceTool;
use App\Ai\Tools\PresentUserFormTool;
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

    public function __construct(
        private User $user,
        private BuildAiWriteProposal $writeProposals,
        private BuildAiUpdateProposal $updateProposals,
        private PublishHtmlArtifactTool $htmlArtifacts,
    ) {}

    public function instructions(): Stringable|string
    {
        $today = now('Asia/Taipei')->toDateString();
        $metricGuidance = collect(config('ai_metrics', []))->map(function (array $metric) {
            $aliases = implode('、', $metric['aliases'] ?? []);

            return "- {$metric['label']}（同义词：{$aliases}）：{$metric['formula']}；单位 {$metric['unit']}；空值策略：{$metric['null_strategy']}。";
        })->implode("\n");

        return <<<PROMPT
你是鑫源昌智造中枢里的业务助手。你可以查询当前用户有权限的数据，也可以为有限业务对象准备新增或修改草稿。

当前业务日期：{$today}（Asia/Taipei）。“本月”指当前自然月。

规则：
- 只能通过工具读取数据或准备写入草稿，不允许要求或编写 SQL。
- 工具返回无权限、不可见、无数据时，直接说明限制，不要猜测。
- 第一版只允许准备采购申请 requisition、现场报工 team_log、客户信息 customer、客户联系人 customer_contact、物料资料 material 的新增草稿。
- 第一版只允许修改客户信息 customer、客户联系人 customer_contact、物料资料 material 的普通字段。禁止修改状态、审批、编号、关联项目、所属客户等流程或关联字段。
- 新增业务数据必须先查询并使用真实关联记录 UUID；禁止猜测 UUID。
- 修改前必须先用 query_object_records 精确查找，再用 get_object_record 核对唯一记录和最新值。若无法唯一确定记录，必须让用户选择，禁止猜测 record_id。
- 用户要求新增资料但缺少一个或多个字段时，主动调用 present_user_form，一次展示最多 6 个缺失字段供用户快速填写，然后等待用户提交。不要只用文字追问。
- 单个问题有 2 至 4 个简短选项，或需要用户在多条候选记录中确认时，可调用 present_user_choice。
- 关系字段必须先查询真实记录，再把友好名称作为 select 的 label、真实 UUID 作为 value；禁止让用户手工输入 UUID。
- 资料完整时调用 prepare_object_record_create；该工具只生成待确认卡片，不会写入。最终确认只能由用户点击卡片完成。
- 修改资料时调用 prepare_object_record_update；payload 只传需要修改的字段。该工具只生成前后差异卡片，不会直接更新。
- 你没有确认、审批、删除、财务写入或批量写入能力。新增和修改的最终确认只能由用户点击卡片完成。不得声称已经写入或更新，除非用户界面返回成功。
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
            new PresentUserChoiceTool,
            new PresentUserFormTool,
            new PrepareObjectRecordCreateTool($this->user, $this->writeProposals),
            new PrepareObjectRecordUpdateTool($this->user, $this->updateProposals),
            $this->htmlArtifacts,
        ];
    }

    public function maxSteps(): int
    {
        return 8;
    }

    protected function maxConversationMessages(): int
    {
        return 24;
    }
}
