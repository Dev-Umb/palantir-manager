<?php

namespace Tests\Feature;

use App\Actions\BuildHubResearch;
use App\Actions\ExecuteHubStage;
use App\Ai\HubAgentRunner;
use App\Ai\HubAnalystAgent;
use App\Ai\HubCollectorAgent;
use App\Ai\HubRecommenderAgent;
use App\Ai\HubSupervisorAgent;
use App\Jobs\RunHubStage;
use App\Models\BusinessObject;
use App\Models\HubEvidence;
use App\Models\HubNotice;
use App\Models\HubRun;
use App\Models\HubStep;
use App\Models\ObjectRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Contracts\Conversational;
use Tests\TestCase;

class HubPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(bool $profile = true, bool $invalid = false): array
    {
        Queue::fake();
        $notice = HubNotice::factory()->create();
        $evidence = HubEvidence::factory()->create(['hub_notice_id' => $notice->id]);
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'business'], ['label' => '业务']);
        $role->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['key' => 'object.project.view'], ['label' => '项目查看', 'module' => 'project', 'action' => 'view'])->id]);
        $user->roles()->attach($role);
        $object = BusinessObject::firstOrCreate(['key' => 'project'], ['label' => '项目主档', 'group' => '主数据', 'code_prefix' => 'P', 'title_field' => 'name', 'fields' => [], 'roles' => []]);
        $company = $profile ? ObjectRecord::create(['business_object_id' => $object->id, 'created_by' => $user->id, 'title' => '钢模板项目', 'code' => 'P-1', 'payload' => ['name' => '钢模板项目', 'business_owner_user_id' => (string) $user->id]]) : null;
        $report = ['claims' => [['text' => '该公告包含钢模板采购。', 'evidence_ids' => [$invalid ? 999999 : $evidence->id], 'quotes' => [['evidence_id' => $evidence->id, 'quote' => '钢模板采购公开公告']]]], 'limitations' => ['公开样本有限']];
        $runner = $this->mock(HubAgentRunner::class);
        $runner->shouldReceive('run')->andReturnUsing(function (string $stage, array $input) use ($report, $evidence, $company): array {
            $output = match ($stage) {
                'plan' => ['source_ids' => [], 'tasks' => ['分析'], 'gaps' => [], 'evidence_ids' => [$evidence->id]],
                'analyze' => $report,
                'recommend' => [...$report, 'claims' => array_map(fn ($section) => [...$report['claims'][0], 'section' => $section], ['decision', 'match', 'qualification', 'counterexample', 'window', 'action']), 'score' => 80, 'verdict' => 'conditional', 'project_references' => $company ? [['project_id' => $company->id, 'field' => 'name', 'quote' => '钢模板项目']] : []],
                'audit' => ['decision' => 'pass', 'issues' => [], 'checked_evidence_ids' => [$evidence->id]],
            };

            return ['output' => $output, 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]];
        });
        $run = HubRun::factory()->create(['hub_notice_id' => $notice->id, 'user_id' => $user->id]);

        return [$run, $notice, $evidence];
    }

    private function step(HubRun $run): void
    {
        $run->refresh();
        (new RunHubStage($run->id, $run->stage, $run->round))->handle(app(ExecuteHubStage::class));
        $run->refresh();
    }

    public function test_four_separate_calls_publish_only_after_audit_with_project_only_queries(): void
    {
        [$run] = $this->scenario();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        foreach (['analyze', 'recommend', 'audit'] as $next) {
            $this->step($run);
            $this->assertSame($next, $run->stage);
            $this->assertNull($run->published_at);
        }
        $this->step($run);
        $this->assertSame('published', $run->status);
        $this->assertSame(80, $run->score['value']);
        $this->assertCount(4, $run->steps);
        $this->assertNotEmpty($run->result['analysis']['claims']);
        $this->assertDoesNotMatchRegularExpression('/project_notifications|tender_notifications/i', implode("\n", $queries));
        $this->step($run);
        $this->assertSame(4, HubStep::count());
    }

    public function test_invalid_evidence_cannot_publish_despite_auditor_pass_and_stops_after_two_corrections(): void
    {
        [$run] = $this->scenario(invalid: true);
        for ($i = 0; $i < 12; $i++) {
            $this->step($run);
        }
        $this->assertSame('insufficient', $run->status);
        $this->assertSame(2, $run->round);
        $this->assertNull($run->published_at);
        $this->assertContains('引用不属于本次研究快照。', $run->audit['issues']);
        $this->step($run);
        $this->assertSame(12, HubStep::count());
        $this->assertTrue($run->steps()->where('stage', 'audit')->get()->every(fn ($step) => $step->usage['preflight_rejected'] ?? false));
    }

    public function test_no_authorized_projects_never_exposes_a_score(): void
    {
        [$run] = $this->scenario(profile: false);
        for ($i = 0; $i < 4; $i++) {
            $this->step($run);
        }
        $this->assertSame('published', $run->status);
        $this->assertNull($run->score);
    }

    public function test_changed_input_invalidates_in_flight_output_and_cancelled_jobs_do_nothing(): void
    {
        [$run, $notice] = $this->scenario();
        for ($i = 0; $i < 3; $i++) {
            $this->step($run);
        }
        $notice->increment('revision');
        $this->step($run);
        $this->assertSame('stale', $run->status);
        $this->assertNull($run->published_at);
        $cancelled = HubRun::factory()->create(['cancelled_at' => now(), 'status' => 'cancelled']);
        $this->step($cancelled);
        $this->assertSame(0, $cancelled->steps()->count());
    }

    public function test_cancellation_during_model_call_prevents_publication(): void
    {
        [$run] = $this->scenario();
        $this->mock(HubAgentRunner::class)->shouldReceive('run')->once()->andReturnUsing(function () use ($run): array {
            $run->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return ['output' => ['source_ids' => []], 'usage' => []];
        });
        $this->step($run);
        $this->assertSame('cancelled', $run->status);
        $this->assertNull($run->published_at);
        $this->assertSame('cancelled', $run->steps()->first()->status);
    }

    public function test_target_evidence_survives_sample_limit_and_old_stage_deliveries_do_nothing(): void
    {
        [$run, $notice, $evidence] = $this->scenario();
        HubEvidence::factory()->count(30)->create();
        $snapshot = app(BuildHubResearch::class)->snapshot($run);
        $this->assertContains($evidence->id, $snapshot['evidence_ids']);
        $this->assertCount(10, $snapshot['evidence_ids']);
        $this->step($run);
        (new RunHubStage($run->id, 'plan', 0))->handle(app(ExecuteHubStage::class));
        $this->assertSame(1, $run->steps()->count());
    }

    public function test_sdk_structured_response_is_consumed_without_tools_or_conversation_history(): void
    {
        config(['ai.providers.aimon' => ['driver' => 'openai', 'key' => 'test', 'url' => 'https://aimon.umb.ink/v1']]);
        HubAnalystAgent::fake([['claims' => [['text' => '公开样本有限', 'evidence_ids' => [1]]], 'limitations' => []]]);
        $response = (new HubAgentRunner)->run('analyze', ['evidence_ids' => [1]]);
        $this->assertSame('公开样本有限', $response['output']['claims'][0]['text']);
        $this->assertArrayHasKey('prompt_tokens', $response['usage']);
        $this->assertSame('gpt-5.6-luna', $response['usage']['model']);
        $this->assertSame('low', $response['usage']['reasoning_effort']);
        foreach ([new HubCollectorAgent, new HubAnalystAgent, new HubRecommenderAgent, new HubSupervisorAgent] as $agent) {
            $this->assertSame(['reasoning' => ['effort' => 'low'], 'store' => false], $agent->providerOptions('openai'));
            $this->assertSame(3500, $agent->maxTokens());
            $this->assertFalse(method_exists($agent, 'tools'));
            $this->assertFalse($agent instanceof Conversational);
        }
    }
}
