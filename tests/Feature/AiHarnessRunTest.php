<?php

namespace Tests\Feature;

use App\Ai\AiRunEventPublisher;
use App\Ai\AiToolEventProjector;
use App\Ai\XycDataAgent;
use App\Events\AiRunEventCreated;
use App\Jobs\RunAiHarness;
use App\Models\AiRun;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Tests\TestCase;

class AiHarnessRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.default' => 'ark',
            'ai.conversations.generate_title' => false,
            'ai.providers.ark.key' => 'test-key',
            'broadcasting.default' => 'null',
        ]);
    }

    public function test_user_can_create_a_queued_ai_run_idempotently(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        Queue::fake();

        $user = $this->userWithRole('production');
        $payload = [
            'message' => '按项目阶段统计项目数量',
            'client_request_id' => '019f4b74-f729-7d91-b611-30d4898f9303',
        ];
        $runReads = [];
        DB::listen(function ($query) use (&$runReads) {
            if (str_contains($query->sql, 'from "ai_runs"') && str_contains($query->sql, '"id"')) {
                $runReads[] = $query->sql;
            }
        });

        $first = $this->actingAs($user)->postJson('/ai/runs', $payload);

        $first->assertStatus(202)
            ->assertJsonPath('run.status', 'queued')
            ->assertJsonPath('run.input', $payload['message'])
            ->assertJsonStructure([
                'conversation_id',
                'channel',
                'run' => ['id', 'status', 'input', 'last_event_seq', 'created_at'],
            ]);
        $this->assertCount(1, $runReads, 'Creating a run must only read it once to allocate the event sequence.');

        $runId = $first->json('run.id');

        $this->assertDatabaseHas('ai_runs', [
            'id' => $runId,
            'user_id' => $user->id,
            'client_request_id' => $payload['client_request_id'],
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('ai_run_events', [
            'run_id' => $runId,
            'seq' => 1,
            'type' => 'run.queued',
        ]);
        Queue::assertPushed(fn (RunAiHarness $job) => $job->runId === $runId);

        $second = $this->postJson('/ai/runs', $payload);

        $second->assertStatus(202)->assertJsonPath('run.id', $runId);
        $this->assertDatabaseCount('ai_runs', 1);
        Queue::assertPushed(RunAiHarness::class, 1);
    }

    public function test_harness_v2_endpoints_are_hidden_when_the_feature_flag_is_disabled(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        config(['ai.harness_v2' => false]);
        $user = $this->userWithRole('production');

        $this->actingAs($user)->get('/ai')->assertNotFound();
        $this->postJson('/ai/runs', [
            'message' => '按项目阶段统计项目数量',
            'client_request_id' => fake()->uuid(),
        ])->assertNotFound();
        $this->assertDatabaseCount('ai_runs', 0);
    }

    public function test_run_events_can_be_replayed_after_a_sequence_number(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        Queue::fake();
        $user = $this->userWithRole('production');
        $runId = $this->createRun($user);
        $run = AiRun::findOrFail($runId);

        Event::fake([AiRunEventCreated::class]);
        $published = app(AiRunEventPublisher::class)->publish($run, 'activity.updated', [
            'label' => '正在理解问题',
            'status' => 'running',
        ]);

        $this->assertFalse($run->isDirty('last_event_seq'), 'Publishing an event must not leave a stale sequence pending on the run model.');

        Event::assertDispatched(AiRunEventCreated::class, function (AiRunEventCreated $event) use ($run, $published) {
            return $event->envelope === $published->envelope()
                && $event->broadcastOn()->name === "private-ai.runs.{$run->id}";
        });

        $this->actingAs($user)
            ->getJson("/ai/runs/{$runId}/events?after=1")
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.seq', 2)
            ->assertJsonPath('events.0.type', 'activity.updated')
            ->assertJsonPath('events.0.payload.label', '正在理解问题')
            ->assertJsonPath('last_event_seq', 2);
    }

    public function test_run_details_and_cancellation_are_scoped_to_the_owner(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        Queue::fake();
        $owner = $this->userWithRole('production');
        $runId = $this->createRun($owner);
        $other = $this->userWithRole('finance');

        $this->actingAs($other)->getJson("/ai/runs/{$runId}")->assertForbidden();
        $this->postJson("/ai/runs/{$runId}/cancel")->assertForbidden();

        $this->actingAs($owner)
            ->getJson("/ai/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('run.id', $runId)
            ->assertJsonPath('run.status', 'queued');

        $this->postJson("/ai/runs/{$runId}/cancel")
            ->assertOk()
            ->assertJsonPath('run.status', 'cancelled');

        $this->assertDatabaseHas('ai_run_events', [
            'run_id' => $runId,
            'seq' => 2,
            'type' => 'run.cancelled',
        ]);
    }

    public function test_harness_streams_answer_events_and_completes_the_run(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        Queue::fake();
        $user = $this->userWithRole('production');
        $runId = $this->createRun($user);
        Ai::fakeAgent(XycDataAgent::class, [
            "## 项目阶段\n\n当前共有 3 个阶段。",
        ]);

        app()->call([new RunAiHarness($runId), 'handle']);

        $this->assertDatabaseHas('ai_runs', [
            'id' => $runId,
            'status' => 'completed',
            'answer' => "## 项目阶段\n\n当前共有 3 个阶段。",
        ]);

        $events = AiRun::findOrFail($runId)->events()->get();
        $this->assertSame([
            'run.queued',
            'run.started',
            'activity.updated',
            'answer.delta',
            'run.completed',
        ], $events->pluck('type')->all());
        $this->assertSame(
            "## 项目阶段\n\n当前共有 3 个阶段。",
            $events->firstWhere('type', 'answer.delta')->payload['delta'],
        );
    }

    public function test_harness_does_not_query_cancellation_for_every_text_delta(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        Queue::fake();
        $user = $this->userWithRole('production');
        $runId = $this->createRun($user);
        Ai::fakeAgent(XycDataAgent::class, [
            'one two three four five six seven eight nine ten',
        ]);
        $cancellationChecks = [];
        DB::listen(function ($query) use (&$cancellationChecks) {
            if (str_contains($query->sql, '"cancel_requested_at" is not null')) {
                $cancellationChecks[] = $query->sql;
            }
        });

        app()->call([new RunAiHarness($runId), 'handle']);

        $this->assertCount(2, $cancellationChecks, 'Cancellation is checked at stream start and before completion, not per token.');
        $this->assertSame('completed', AiRun::findOrFail($runId)->status);
    }

    public function test_tool_events_publish_safe_progress_and_structured_artifacts(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        Queue::fake();
        $user = $this->userWithRole('admin');
        $run = AiRun::findOrFail($this->createRun($user));
        $projector = app(AiToolEventProjector::class);
        $call = new ToolCall('call-1', 'query_object_records', [
            'object' => 'project',
            'select' => ['title', 'arrears'],
            'sort' => ['field' => 'arrears', 'direction' => 'desc'],
        ]);

        $projector->started($run, $call);
        $projector->completed($run, new ToolResult(
            'call-1',
            'query_object_records',
            $call->arguments,
            json_encode([
                'ok' => true,
                'object' => ['key' => 'project', 'label' => '项目主档'],
                'fields' => [
                    ['key' => 'title', 'label' => '项目名称', 'type' => 'text'],
                    ['key' => 'arrears', 'label' => '欠款', 'type' => 'number'],
                ],
                'record_count' => 1,
                'rows' => [['title' => '项目 A', 'arrears' => 800]],
                'sources' => [['object_key' => 'project', 'object_label' => '项目主档', 'record_count' => 1]],
                'data_quality' => [],
            ], JSON_UNESCAPED_UNICODE),
        ));

        $run->refresh();
        $this->assertCount(2, $run->artifacts);
        $this->assertSame(['table', 'chart'], collect($run->artifacts)->pluck('type')->all());
        $this->assertSame('project', $run->sources[0]['object_key']);

        $progress = $run->events()->where('type', 'tool.completed')->firstOrFail();
        $this->assertSame('已读取项目主档，共 1 条记录', $progress->payload['label']);
        $this->assertArrayNotHasKey('rows', $progress->payload);
        $this->assertArrayNotHasKey('arguments', $progress->payload);
        $this->assertArrayHasKey('duration_ms', $progress->payload);
        $this->assertSame(2, $run->events()->where('type', 'artifact.upsert')->count());
    }

    public function test_html_tool_progress_does_not_report_a_fake_record_count(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        Queue::fake();
        $run = AiRun::findOrFail($this->createRun($this->userWithRole('admin')));

        app(AiToolEventProjector::class)->completed($run, new ToolResult(
            'call-html',
            'publish_html_artifact',
            ['title' => '项目分析'],
            json_encode([
                'ok' => true,
                'artifact' => [
                    'id' => fake()->uuid(),
                    'type' => 'html',
                    'title' => '项目分析',
                    'revision' => 1,
                    'data' => ['html' => '<p>完成</p>'],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ));

        $progress = $run->events()->where('type', 'tool.completed')->firstOrFail();
        $this->assertSame('静态 HTML 结果已生成', $progress->payload['label']);
        $this->assertArrayNotHasKey('record_count', $progress->payload);
    }

    public function test_harness_stops_at_the_next_stream_event_when_cancellation_is_requested(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        Queue::fake();
        $user = $this->userWithRole('production');
        $runId = $this->createRun($user);
        Ai::fakeAgent(XycDataAgent::class, [function () use ($runId) {
            AiRun::whereKey($runId)->update(['cancel_requested_at' => now()]);

            return '这段内容不应成为最终答案';
        }]);

        app()->call([new RunAiHarness($runId), 'handle']);

        $run = AiRun::findOrFail($runId);
        $this->assertSame('cancelled', $run->status);
        $this->assertNull($run->answer);
        $this->assertSame('run.cancelled', $run->events()->reorder('seq', 'desc')->firstOrFail()->type);
        $this->assertSame(0, $run->events()->where('type', 'run.completed')->count());
    }

    public function test_conversation_history_returns_run_snapshots_and_events(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        Queue::fake();
        $user = $this->userWithRole('production');
        $run = AiRun::findOrFail($this->createRun($user));
        app(AiRunEventPublisher::class)->publish($run, 'activity.updated', [
            'label' => '正在理解问题',
            'status' => 'running',
        ]);

        $this->actingAs($user)
            ->getJson("/ai/conversations/{$run->conversation_id}")
            ->assertOk()
            ->assertJsonPath('runs.0.id', $run->id)
            ->assertJsonPath('runs.0.input', '列出我能看的数据表')
            ->assertJsonPath('runs.0.events.0.type', 'run.queued')
            ->assertJsonPath('runs.0.events.1.type', 'activity.updated')
            ->assertJsonStructure(['messages', 'runs']);
    }

    public function test_harness_retries_provider_failure_before_any_answer_is_emitted(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        Queue::fake();
        $user = $this->userWithRole('production');
        $runId = $this->createRun($user);
        Ai::fakeAgent(XycDataAgent::class, [fn () => throw new \RuntimeException('temporary')]);
        $job = (new RunAiHarness($runId))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        $job->assertReleased(1);
        $run = AiRun::findOrFail($runId);
        $this->assertSame('queued', $run->status);
        $this->assertSame('run.retrying', $run->events()->reorder('seq', 'desc')->firstOrFail()->type);
    }

    public function test_harness_marks_provider_failure_after_the_last_attempt(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        Queue::fake();
        $user = $this->userWithRole('production');
        $runId = $this->createRun($user);
        Ai::fakeAgent(XycDataAgent::class, [fn () => throw new \RuntimeException('persistent')]);
        $job = (new RunAiHarness($runId))->withFakeQueueInteractions();
        $job->job->attempts = 3;

        app()->call([$job, 'handle']);

        $job->assertNotReleased();
        $run = AiRun::findOrFail($runId);
        $this->assertSame('failed', $run->status);
        $this->assertSame('run.failed', $run->events()->reorder('seq', 'desc')->firstOrFail()->type);
    }

    public function test_queue_level_failure_does_not_leave_the_run_running(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        Queue::fake();
        $run = AiRun::findOrFail($this->createRun($this->userWithRole('production')));
        $run->update(['status' => 'running', 'started_at' => now()->subSeconds(211)]);

        (new RunAiHarness($run->id))->failed(new \RuntimeException('worker timeout'));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('worker_failed', $run->error['code']);
        $this->assertSame('run.failed', $run->events()->reorder('seq', 'desc')->firstOrFail()->type);
    }

    private function createRun(User $user): string
    {
        return $this->actingAs($user)->postJson('/ai/runs', [
            'message' => '列出我能看的数据表',
            'client_request_id' => fake()->uuid(),
        ])->assertStatus(202)->json('run.id');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::create([
            'name' => Role::where('name', $roleName)->firstOrFail()->label,
            'email' => "{$roleName}-harness@example.com",
            'password' => Hash::make('password123'),
        ]);

        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
