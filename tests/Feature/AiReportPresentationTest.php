<?php

namespace Tests\Feature;

use App\Ai\AiArtifactFactory;
use App\Ai\HtmlArtifactSanitizer;
use App\Ai\Tools\PublishHtmlArtifactTool;
use App\Ai\XycDataAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class AiReportPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_exposes_report_tool_and_preserves_output_choice_and_data_scope_rules(): void
    {
        $agent = XycDataAgent::make(user: new User);
        $instructions = (string) $agent->instructions();
        $this->assertStringContainsString('不需要用户额外说“HTML”', $instructions);
        $this->assertStringContainsString('用户明确要求纯文字、表格或明细时优先遵从', $instructions);
        $this->assertStringContainsString('简单数字、状态或单条资料查询', $instructions);
        $this->assertStringContainsString('受 limit 限制的明细不得冒充全量统计', $instructions);
        $this->assertStringContainsString('当前用户可见数据', $instructions);
        $this->assertStringContainsString('不宣称报告已生成', $instructions);
        $this->assertStringContainsString('以图表为主、短句为辅', $instructions);
        $this->assertStringContainsString('每节最多 1–2 句', $instructions);
        $this->assertStringContainsString('缺失或无法核实的数据不画成 0', $instructions);
        $this->assertTrue(collect($agent->tools())->contains(fn ($tool) => $tool instanceof PublishHtmlArtifactTool));
    }

    public function test_report_publication_retains_safe_content_without_executable_or_remote_markup(): void
    {
        $result = json_decode((string) (new PublishHtmlArtifactTool(new HtmlArtifactSanitizer))->handle(new Request([
            'title' => '回款报告',
            'html' => '<h1>已回款 80 万元</h1><p onclick="alert(1)">来源：业务项目</p><script>alert(1)</script><iframe src="https://evil.example"></iframe><img src="https://evil.example/pixel"><form><input></form>',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($result['ok']);
        $artifact = $result['artifact'];
        $this->assertSame('html', $artifact['type']);
        $this->assertSame('回款报告', $artifact['title']);
        $this->assertStringContainsString('已回款 80 万元', $artifact['data']['html']);
        $this->assertStringContainsString('来源：业务项目', $artifact['data']['html']);
        foreach (['<script', '<iframe', '<form', 'onclick', 'https://evil.example'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $artifact['data']['html']);
        }
        $this->assertSame([$artifact], (new AiArtifactFactory)->fromToolResult('publish_html_artifact', $result)['artifacts']);
    }

    public function test_static_comparison_bars_keep_units_labels_and_inline_styles_after_publication(): void
    {
        $result = json_decode((string) (new PublishHtmlArtifactTool(new HtmlArtifactSanitizer))->handle(new Request([
            'title' => '业务员发货对比',
            'html' => '<h2>已记录发货（吨）</h2><div role="img" aria-label="业务员甲发货15吨"><span>业务员甲</span><div style="width:75%;background:#2f6f9f;height:12px"></div><strong>15 吨</strong></div><p>回款缺少流水，未绘制图表。</p>',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($result['ok']);
        $html = $result['artifact']['data']['html'];
        $this->assertStringContainsString('width:75%', $html);
        $this->assertStringContainsString('业务员甲', $html);
        $this->assertStringContainsString('15 吨', $html);
        $this->assertStringContainsString('回款缺少流水，未绘制图表。', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_report_rejection_does_not_create_a_report_and_query_details_remain_available(): void
    {
        $result = json_decode((string) (new PublishHtmlArtifactTool(new HtmlArtifactSanitizer))->handle(new Request([
            'title' => '过大报告',
            'html' => str_repeat('a', HtmlArtifactSanitizer::MAX_BYTES + 1),
        ])), true, flags: JSON_THROW_ON_ERROR);
        $factory = new AiArtifactFactory;
        $this->assertFalse($result['ok']);
        $this->assertSame([], $factory->fromToolResult('publish_html_artifact', $result)['artifacts']);

        $query = $factory->fromToolResult('query_object_records', [
            'ok' => true,
            'object' => ['label' => '业务项目'],
            'fields' => [['key' => 'name', 'label' => '项目名称'], ['key' => 'amount', 'label' => '金额', 'type' => 'number']],
            'rows' => [['name' => '桥梁项目', 'amount' => 0], ['name' => '厂房项目', 'amount' => 800000]],
            'sources' => [['object_key' => 'project']],
            'data_quality' => [['message' => '部分日期缺失']],
        ]);
        $this->assertSame(['table', 'chart'], array_column($query['artifacts'], 'type'));
        $this->assertSame(0, $query['artifacts'][0]['data']['rows'][0]['amount']);
        $this->assertCount(2, $query['artifacts'][0]['data']['rows']);
        $this->assertSame('项目名称', $query['artifacts'][0]['data']['columns'][0]['label']);
        $this->assertSame([['object_key' => 'project']], $query['sources']);
        $this->assertSame([['message' => '部分日期缺失']], $query['data_quality']);
    }
}
