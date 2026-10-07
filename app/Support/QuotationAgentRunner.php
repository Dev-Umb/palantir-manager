<?php

namespace App\Support;

use App\Ai\QuotationChatGateway;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Providers\OpenAiProvider;
use RuntimeException;

class QuotationAgentRunner
{
    public function run(Agent $agent, array $input, array $attachments = []): array
    {
        $provider = config('ai.default', 'ark');
        if (blank(config("ai.providers.{$provider}.key")) && ! Ai::hasFakeGatewayFor($agent::class)) {
            throw new RuntimeException('报价 AI 服务未配置，仍可手动填写参数并报价。');
        }
        if ($provider === 'aimon' && ! Ai::hasFakeGatewayFor($agent::class)) {
            config(['ai.providers.quotation_shared_chat' => [...config("ai.providers.{$provider}"), 'driver' => 'quotation-shared-chat']]);
            Ai::extend('quotation-shared-chat', fn ($app, $config) => new OpenAiProvider(new QuotationChatGateway($app->make(Dispatcher::class)), $config, $app->make(Dispatcher::class)));
            $provider = 'quotation_shared_chat';
        }
        $timeout = (int) config('ai.request_timeout', 180);
        set_time_limit($timeout + 20);
        $response = $agent->prompt(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), attachments: $attachments, provider: $provider, timeout: $timeout);

        $result = $response->toArray();
        if ($result === []) {
            throw new RuntimeException('AI 返回为空，请重试。');
        }

        return $result;
    }
}
