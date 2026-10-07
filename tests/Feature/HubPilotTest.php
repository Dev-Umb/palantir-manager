<?php

namespace Tests\Feature;

use App\Ai\HubAgentRunner;
use App\Support\HubEvidenceRules;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubPilotTest extends TestCase
{
    public function test_luna_uses_chat_low_reasoning_without_changing_platform_provider(): void
    {
        config(['procurement_hub.allow_project_ai' => true, 'ai.providers.aimon' => ['driver' => 'openai', 'key' => 'testing', 'url' => 'https://aimon.umb.ink/v1']]);
        $previous = config('ai.default');
        Http::preventStrayRequests();
        Http::fake(['aimon.umb.ink/v1/chat/completions' => Http::response([
            'model' => 'gpt-5.6-luna', 'choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(['claims' => [], 'limitations' => []])]]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
        ])]);
        $result = app(HubAgentRunner::class)->run('analyze', ['projects' => [['name' => '不能发给分析阶段的主档']], 'evidence' => []]);
        Http::assertSent(fn ($request) => $request['model'] === 'gpt-5.6-luna' && $request['reasoning_effort'] === 'low' && $request['max_completion_tokens'] === 3500 && $request['store'] === false
            && ! str_contains($request['messages'][1]['content'], '不能发给分析阶段') && ! isset($request['tools'])
            && str_contains($request['messages'][0]['content'], 'schema'));
        $this->assertSame($previous, config('ai.default'));
        $this->assertSame(20, $result['usage']['prompt_tokens']);
    }

    public function test_wrong_model_or_truncated_response_never_becomes_a_report(): void
    {
        config(['ai.providers.aimon' => ['driver' => 'openai', 'key' => 'testing', 'url' => 'https://aimon.umb.ink/v1']]);
        Http::fake(['*' => Http::response(['model' => 'another-model', 'choices' => [['finish_reason' => 'length', 'message' => ['content' => '{}']]]])]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('未发布报告');
        app(HubAgentRunner::class)->run('analyze', []);
    }

    public function test_missing_claim_text_cannot_pass_evidence_validation(): void
    {
        $errors = app(HubEvidenceRules::class)->reportErrors(['claims' => [['claim' => '字段错误', 'evidence_ids' => [1], 'quotes' => [['evidence_id' => 1, 'quote' => '原文']]]]], ['evidence_ids' => [1], 'evidence' => [['id' => 1, 'text' => '原文']]]);
        $this->assertContains('结论正文缺失，不能发布。', $errors);
    }

    public function test_refresh_is_scheduled_at_midnight_and_noon_in_china(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'hub:sync'));
        $this->assertSame('0 0,12 * * *', $event->expression);
        $this->assertSame('Asia/Shanghai', $event->timezone);
    }

    public function test_internal_counterexample_requires_project_references_and_external_claims_still_require_sources(): void
    {
        $report = ['claims' => [['section' => 'counterexample', 'text' => '主档中存在未中记录', 'evidence_ids' => [], 'quotes' => []]], 'project_references' => [['project_id' => 'P1', 'field' => 'remark', 'quote' => '未中']]];
        $rules = app(HubEvidenceRules::class);
        $this->assertSame([], $rules->reportErrors($report, []));
        unset($report['project_references']);
        $this->assertContains('结论缺少证据引用。', $rules->reportErrors($report, []));
        $report['project_references'] = [['project_id' => 'P1']];
        $report['claims'][0]['section'] = 'price';
        $this->assertContains('结论缺少证据引用。', $rules->reportErrors($report, []));
    }

    public function test_source_passage_keys_resolve_to_literal_evidence_and_unknown_keys_fail_closed(): void
    {
        config(['ai.providers.aimon' => ['driver' => 'openai', 'key' => 'testing', 'url' => 'https://aimon.umb.ink/v1']]);
        Http::fake(['*' => Http::response(['model' => 'gpt-5.6-luna', 'choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(['claims' => [
            ['section' => 'price', 'text' => '这是候选报价', 'quotes' => [['quote_key' => 'E8-1']]],
            ['section' => 'price', 'text' => '非法引用', 'quotes' => [['quote_key' => 'E999-1']]],
        ], 'limitations' => []])]]]])]);
        $response = app(HubAgentRunner::class)->run('analyze', ['evidence' => [['id' => 8, 'notice_id' => 2, 'text' => '候选报价21204000元。']]]);
        $this->assertSame([8], $response['output']['claims'][0]['evidence_ids']);
        $this->assertSame('候选报价21204000元。', $response['output']['claims'][0]['quotes'][0]['quote']);
        $this->assertSame([-1], $response['output']['claims'][1]['evidence_ids']);
        Http::assertSent(fn ($request) => str_contains($request['messages'][1]['content'], 'E8-1'));
    }

    public function test_auditor_receives_current_results_without_previous_auditor_opinions(): void
    {
        config(['ai.providers.aimon' => ['driver' => 'openai', 'key' => 'testing', 'url' => 'https://aimon.umb.ink/v1']]);
        Http::fake(['*' => Http::response(['model' => 'gpt-5.6-luna', 'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"decision":"pass","issues":[],"checked_evidence_ids":[]}']]]])]);
        app(HubAgentRunner::class)->run('audit', ['correction_issues' => ['旧意见不应污染独立审计'], 'previous' => [['stage' => 'recommend', 'output' => ['claims' => [['text' => '当前结论', 'evidence_ids' => []]]]]]]);
        Http::assertSent(fn ($request) => ! str_contains($request['messages'][1]['content'], '旧意见不应污染') && str_contains($request['messages'][1]['content'], '当前结论'));
    }

    public function test_project_field_keys_bind_to_authorized_rows_without_model_copying_ids_or_quotes(): void
    {
        config(['procurement_hub.allow_project_ai' => true, 'ai.providers.aimon' => ['driver' => 'openai', 'key' => 'testing', 'url' => 'https://aimon.umb.ink/v1']]);
        Http::fake(['*' => Http::response(['model' => 'gpt-5.6-luna', 'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"claims":[],"project_references":[{"project_key":"P1:remark"},{"project_key":"P999:remark"}]}']]]])]);
        $result = app(HubAgentRunner::class)->run('recommend', ['projects' => [['id' => 'real-authorized-id', 'title' => '真实模板项目', 'remark' => '价格太低未中']]]);
        $this->assertSame(['project_id' => 'real-authorized-id', 'field' => 'remark', 'quote' => '价格太低未中', 'project_name' => '真实模板项目'], $result['output']['project_references'][0]);
        $this->assertSame('unknown', $result['output']['project_references'][1]['project_id']);
        Http::assertSent(fn ($request) => str_contains($request['messages'][1]['content'], 'P1:remark') && ! str_contains($request['messages'][1]['content'], 'real-authorized-id'));
    }
}
