<?php

namespace App\Ai;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;

class HubChatGateway extends OpenAiGateway
{
    public function generateText(TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): TextResponse
    {
        if ($tools || count($messages) !== 1 || ! $schema) {
            throw new RuntimeException('信息中心只允许单次无工具的结构化调用。');
        }
        $body = [
            'model' => $model,
            'messages' => [['role' => 'system', 'content' => $instructions.' 输出必须是JSON对象，严格遵循以下schema（claims中字段名必须为text）：'.json_encode((new ObjectSchema($schema, strict: true))->toSchema(), JSON_UNESCAPED_UNICODE)], ['role' => 'user', 'content' => $messages[0]->content]],
            'reasoning_effort' => $options?->providerOptions($provider->driver())['reasoning']['effort'] ?? 'low',
            'max_completion_tokens' => $options?->maxTokens ?? 3500,
            'store' => false,
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'hub_report', 'strict' => true, 'schema' => (new ObjectSchema($schema, strict: true))->toSchema()]],
        ];
        $response = $this->client($provider, $timeout)->post('chat/completions', $body)->json();
        $choice = $response['choices'][0] ?? [];
        if (($choice['finish_reason'] ?? '') !== 'stop' || ! empty($choice['message']['refusal']) || ($response['model'] ?? '') !== $model) {
            throw new RuntimeException('模型返回不完整或模型不符，未发布报告。');
        }
        $text = $choice['message']['content'] ?? '';
        $structured = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($structured)) {
            throw new RuntimeException('模型未返回可校验的结构化报告。');
        }

        return new StructuredTextResponse($structured, $text, new Usage(
            promptTokens: $response['usage']['prompt_tokens'] ?? 0,
            completionTokens: $response['usage']['completion_tokens'] ?? 0,
            reasoningTokens: $response['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0,
        ), new Meta($provider->name(), $model));
    }
}
