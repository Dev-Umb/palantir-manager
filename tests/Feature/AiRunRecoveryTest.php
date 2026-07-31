<?php

namespace Tests\Feature;

use App\Ai\AiFailureClassifier;
use App\Ai\AiRunEventPublisher;
use App\Ai\AiToolEventProjector;
use App\Ai\XycDataAgent;
use App\Jobs\RunAiHarness;
use App\Models\AiRun;
use App\Models\User;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Mockery;
use Tests\TestCase;

class AiRunRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_recoverable_failure_retries_after_partial_output_and_resets_projections(): void
    {
        $run = $this->createRun([
            'artifacts' => [['id' => 'form-1', 'type' => 'form']],
            'sources' => [['object_key' => 'project', 'record_count' => 1]],
            'provenance' => [['query_hash' => 'query-1', 'result_hash' => 'result-1']],
            'data_quality' => [['message' => 'partial']],
        ]);
        $exception = $this->requestException(400, json_encode([
            'error' => [
                'code' => 'TransientRejection',
                'message' => 'retry this request',
                'token' => 'must-not-leak',
            ],
        ], JSON_THROW_ON_ERROR));
        $this->fakeAgentStream($this->partialFailureStream($exception));
        Log::spy();
        $job = (new RunAiHarness($run->id))->withFakeQueueInteractions();

        $this->handle($job);

        $job->assertReleased(1);
        $run->refresh();
        $this->assertSame('queued', $run->status);
        $this->assertNull($run->answer);
        $this->assertSame([], $run->artifacts);
        $this->assertSame([], $run->sources);
        $this->assertSame([], $run->provenance);
        $this->assertSame([], $run->data_quality);
        $this->assertNull($run->error);
        $retry = $run->events()->where('type', 'run.retrying')->sole();
        $this->assertTrue($retry->payload['reset_output']);
        $this->assertSame(400, $retry->payload['provider_status']);
        $this->assertStringContainsString('TransientRejection', $retry->payload['provider_response_excerpt']);
        $this->assertStringNotContainsString('must-not-leak', $retry->payload['provider_response_excerpt']);
        $this->assertSame(
            str_repeat('正在准备表单', 30),
            $run->events()->where('type', 'answer.delta')->sole()->payload['delta'],
        );
        Log::shouldHaveReceived('warning')->once()->with(
            'AI provider request failed',
            Mockery::on(fn (array $context): bool => $context['run_id'] === $run->id
                && $context['attempt'] === 1
                && $context['provider_status'] === 400
                && ! str_contains($context['provider_response_excerpt'], 'must-not-leak')),
        );
    }

    public function test_recoverable_failure_without_partial_output_still_retries(): void
    {
        $run = $this->createRun();
        $exception = $this->requestException(503, '{"error":{"message":"temporarily unavailable"}}');
        $this->fakeAgentStream($this->immediateFailureStream($exception));
        $job = (new RunAiHarness($run->id))->withFakeQueueInteractions();

        $this->handle($job);

        $job->assertReleased(1);
        $this->assertSame('queued', $run->refresh()->status);
        $this->assertDatabaseHas('ai_run_events', [
            'run_id' => $run->id,
            'type' => 'run.retrying',
        ]);
    }

    public function test_final_recoverable_attempt_fails_with_sanitized_diagnostic(): void
    {
        $run = $this->createRun();
        $exception = $this->requestException(400, '{"error":{"message":"quota gate","api_key":"must-not-leak"}}');
        $this->fakeAgentStream($this->partialFailureStream($exception));
        $job = (new RunAiHarness($run->id))->withFakeQueueInteractions();
        $job->job->attempts = 3;

        $this->handle($job);

        $job->assertNotReleased();
        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('provider_error', $run->failure_category);
        $this->assertSame(400, $run->error['provider_status']);
        $this->assertStringContainsString('quota gate', $run->error['provider_response_excerpt']);
        $this->assertStringNotContainsString('must-not-leak', $run->error['provider_response_excerpt']);
        $failed = $run->events()->where('type', 'run.failed')->sole();
        $this->assertSame($run->error, $failed->payload);
    }

    public function test_non_recoverable_provider_auth_failure_does_not_retry(): void
    {
        $run = $this->createRun();
        $exception = $this->requestException(401, '{"error":{"message":"unauthorized"}}');
        $this->fakeAgentStream($this->partialFailureStream($exception));
        $job = (new RunAiHarness($run->id))->withFakeQueueInteractions();

        $this->handle($job);

        $job->assertNotReleased();
        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('provider_auth', $run->failure_category);
        $this->assertSame('AI 服务配置无效，请联系管理员。', $run->error['message']);
    }

    private function createRun(array $overrides = []): AiRun
    {
        $user = User::factory()->create();
        $conversationId = app(ConversationStore::class)->storeConversation($user->id, 'AI recovery test');

        return AiRun::create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'user_id' => $user->id,
            'client_request_id' => (string) Str::uuid(),
            'request_hash' => hash('sha256', 'AI recovery test'),
            'attempt_number' => 1,
            'status' => 'queued',
            'input' => '帮我准备一张表单',
            'context_snapshot' => [],
            'artifacts' => [],
            'sources' => [],
            'provenance' => [],
            'data_quality' => [],
            'last_event_seq' => 0,
            ...$overrides,
        ]);
    }

    private function fakeAgentStream(StreamableAgentResponse $stream): void
    {
        $agent = Mockery::mock(XycDataAgent::class);
        $agent->shouldReceive('continue')->once()->andReturnSelf();
        $agent->shouldReceive('stream')->once()->andReturn($stream);
        $this->app->bind(XycDataAgent::class, fn (): XycDataAgent => $agent);
    }

    private function partialFailureStream(RequestException $exception): StreamableAgentResponse
    {
        return new StreamableAgentResponse((string) Str::uuid7(), function () use ($exception) {
            yield new TextDelta(
                id: (string) Str::uuid7(),
                messageId: (string) Str::uuid7(),
                delta: str_repeat('正在准备表单', 30),
                timestamp: time(),
            );

            throw $exception;
        });
    }

    private function immediateFailureStream(RequestException $exception): StreamableAgentResponse
    {
        return new StreamableAgentResponse((string) Str::uuid7(), function () use ($exception) {
            if (false) {
                yield;
            }

            throw $exception;
        });
    }

    private function requestException(int $status, string $body): RequestException
    {
        return new RequestException(new Response(new PsrResponse($status, [], $body)));
    }

    private function handle(RunAiHarness $job): void
    {
        $job->handle(
            app(AiRunEventPublisher::class),
            app(AiToolEventProjector::class),
            app(AiFailureClassifier::class),
        );
    }
}
