<?php

namespace App\Ai;

use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;

class QuotationChatGateway extends OpenAiGateway
{
    public function generateText(TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): TextResponse
    {
        if ($tools || count($messages) !== 1 || ! $schema) {
            throw new RuntimeException('参考报价只允许单次无工具的结构化调用。');
        }
        $content = [['type' => 'text', 'text' => $messages[0]->content]];
        foreach ($this->mapAttachments($messages[0]->attachments ?? collect()) as $part) {
            $content[] = $part['type'] === 'input_image'
                ? ['type' => 'image_url', 'image_url' => ['url' => $part['image_url']]]
                : ['type' => 'file', 'file' => array_diff_key($part, ['type' => true])];
        }
        $body = [
            'model' => $model,
            'messages' => [['role' => 'system', 'content' => $instructions.' 输出必须是JSON对象，严格遵循以下schema：'.json_encode((new ObjectSchema($schema, strict: true))->toSchema(), JSON_UNESCAPED_UNICODE)], ['role' => 'user', 'content' => $content]],
            'reasoning_effort' => $options?->providerOptions($provider->driver())['reasoning']['effort'] ?? 'low',
            'max_completion_tokens' => $options?->maxTokens ?? 3500,
            'store' => false,
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'quotation_report', 'strict' => true, 'schema' => (new ObjectSchema($schema, strict: true))->toSchema()]],
        ];
        $started = microtime(true);
        $budget = $timeout ?? 180;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $remaining = max(1, (int) floor($budget - (microtime(true) - $started)));
            try {
                $response = $this->client($provider, $remaining)->post('chat/completions', $body)->json();
                break;
            } catch (RequestException $exception) {
                if ($attempt === 1 || ! in_array($exception->response->status(), [429, 500, 502, 503, 504], true) || microtime(true) - $started >= $budget - 1) {
                    throw $exception;
                }
            }
        }
        $choice = $response['choices'][0] ?? [];
        if (($choice['finish_reason'] ?? '') !== 'stop' || ! empty($choice['message']['refusal']) || ($response['model'] ?? '') !== $model) {
            throw new RuntimeException('模型返回不完整或模型不符，请重试。');
        }
        $text = $choice['message']['content'] ?? '';
        $structured = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($structured)) {
            throw new RuntimeException('模型未返回可校验的报价信息。');
        }

        return new StructuredTextResponse($structured, $text, new Usage(
            promptTokens: $response['usage']['prompt_tokens'] ?? 0,
            completionTokens: $response['usage']['completion_tokens'] ?? 0,
            reasoningTokens: $response['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0,
        ), new Meta($provider->name(), $model));
    }
}
