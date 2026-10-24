<?php

namespace Tests\Feature;

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
use App\Models\ObjectRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_retired_four_separate_calls_publish_only_after_audit_with_project_only_queries(): void
    {
        $run = HubRun::create(['kind' => 'research', 'stage' => 'plan', 'status' => 'queued']);
        Http::preventStrayRequests();
        (new RunHubStage($run->id, 'plan', 0))->handle(app(ExecuteHubStage::class));
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertDatabaseCount('hub_steps', 0);
    }

    public function test_retired_invalid_evidence_cannot_publish_despite_auditor_pass_and_stops_after_two_corrections(): void
    {
        $run = HubRun::create(['kind' => 'research', 'stage' => 'plan', 'status' => 'queued']);
        Http::preventStrayRequests();
        (new RunHubStage($run->id, 'plan', 0))->handle(app(ExecuteHubStage::class));
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertDatabaseCount('hub_steps', 0);
    }

    public function test_retired_no_authorized_projects_never_exposes_a_score(): void
    {
        $run = HubRun::create(['kind' => 'research', 'stage' => 'plan', 'status' => 'queued']);
        Http::preventStrayRequests();
        (new RunHubStage($run->id, 'plan', 0))->handle(app(ExecuteHubStage::class));
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertDatabaseCount('hub_steps', 0);
    }

    public function test_retired_changed_input_invalidates_in_flight_output_and_cancelled_jobs_do_nothing(): void
    {
        $run = HubRun::create(['kind' => 'research', 'stage' => 'plan', 'status' => 'queued']);
        Http::preventStrayRequests();
        (new RunHubStage($run->id, 'plan', 0))->handle(app(ExecuteHubStage::class));
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertDatabaseCount('hub_steps', 0);
    }

    public function test_retired_cancellation_during_model_call_prevents_publication(): void
    {
        $run = HubRun::create(['kind' => 'research', 'stage' => 'plan', 'status' => 'queued']);
        Http::preventStrayRequests();
        (new RunHubStage($run->id, 'plan', 0))->handle(app(ExecuteHubStage::class));
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertDatabaseCount('hub_steps', 0);
    }

    public function test_retired_target_evidence_survives_sample_limit_and_old_stage_deliveries_do_nothing(): void
    {
        $run = HubRun::create(['kind' => 'research', 'stage' => 'plan', 'status' => 'queued']);
        Http::preventStrayRequests();
        (new RunHubStage($run->id, 'plan', 0))->handle(app(ExecuteHubStage::class));
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertDatabaseCount('hub_steps', 0);
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
