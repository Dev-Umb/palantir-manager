<?php

namespace Tests\Feature;

use App\Ai\Agents\QuotationAssistant;
use App\Support\QuotationAgentRunner;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuotationGatewayTest extends TestCase
{
    public function test_shared_aimon_configuration_supports_structured_chat_and_real_image_bytes(): void
    {
        config(['ai.default' => 'aimon', 'ai.providers.aimon' => ['driver' => 'openai', 'key' => 'synthetic-test-key', 'url' => 'https://example.test/v1', 'models' => ['text' => ['default' => 'gpt-6-astra']]]]);
        $payload = ['answer' => '请确认规格', 'questions' => ['规格是什么？'], 'proposals' => []];
        Http::fake(['example.test/*' => Http::response(['model' => 'gpt-6-astra', 'choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]]])]);
        $file = UploadedFile::fake()->image('quote.png');
        $result = (new QuotationAgentRunner)->run(new QuotationAssistant, ['request' => '74吨'], [$file]);
        $this->assertSame($payload, $result);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.test/v1/chat/completions'
            && $request['model'] === 'gpt-6-astra'
            && $request['store'] === false
            && $request['messages'][1]['content'][1]['image_url']['url'] === 'data:image/png;base64,'.base64_encode($file->get())
            && $request['response_format']['type'] === 'json_schema');
        $this->assertSame('aimon', config('ai.default'));
    }

    public function test_incomplete_model_reply_is_rejected_instead_of_becoming_a_blank_message(): void
    {
        config(['ai.default' => 'aimon', 'ai.providers.aimon' => ['driver' => 'openai', 'key' => 'synthetic-test-key', 'url' => 'https://example.test/v1', 'models' => ['text' => ['default' => 'gpt-6-astra']]]]);
        Http::fake(['example.test/*' => Http::response(['model' => 'gpt-6-astra', 'choices' => [['finish_reason' => 'length', 'message' => ['content' => '']]]])]);
        $this->expectException(\RuntimeException::class);
        (new QuotationAgentRunner)->run(new QuotationAssistant, ['request' => '74吨']);
    }

    public function test_transient_502_is_retried_once_without_changing_provider_or_model(): void
    {
        config(['ai.default' => 'aimon', 'ai.providers.aimon' => ['driver' => 'openai', 'key' => 'synthetic-test-key', 'url' => 'https://example.test/v1', 'models' => ['text' => ['default' => 'gpt-6-astra']]]]);
        $payload = ['answer' => '请核对', 'questions' => [], 'proposals' => []];
        Http::fake(['example.test/*' => Http::sequence()->push(['error' => 'temporary outage'], 502)->push(['model' => 'gpt-6-astra', 'choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($payload)]]]])]);
        $this->assertSame($payload, (new QuotationAgentRunner)->run(new QuotationAssistant, ['request' => '74吨']));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request['model'] === 'gpt-6-astra');
    }

    public function test_persistent_upstream_failure_is_bounded_to_two_attempts(): void
    {
        config(['ai.default' => 'aimon', 'ai.providers.aimon' => ['driver' => 'openai', 'key' => 'synthetic-test-key', 'url' => 'https://example.test/v1', 'models' => ['text' => ['default' => 'gpt-6-astra']]]]);
        Http::fake(['example.test/*' => Http::response(['error' => 'temporary outage'], 502)]);
        try {
            (new QuotationAgentRunner)->run(new QuotationAssistant, ['request' => '74吨']);
            $this->fail('Persistent upstream failure must remain visible.');
        } catch (RequestException $exception) {
            $this->assertSame(502, $exception->response->status());
            Http::assertSentCount(2);
        }
    }

    public function test_authentication_failure_is_not_retried(): void
    {
        config(['ai.default' => 'aimon', 'ai.providers.aimon' => ['driver' => 'openai', 'key' => 'synthetic-test-key', 'url' => 'https://example.test/v1', 'models' => ['text' => ['default' => 'gpt-6-astra']]]]);
        Http::fake(['example.test/*' => Http::response(['error' => 'unauthorized'], 401)]);
        try {
            (new QuotationAgentRunner)->run(new QuotationAssistant, ['request' => '74吨']);
            $this->fail('Invalid authentication must remain visible.');
        } catch (RequestException $exception) {
            $this->assertSame(401, $exception->response->status());
            Http::assertSentCount(1);
        }
    }
}
