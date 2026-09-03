<?php

namespace Tests\Feature;

use Laravel\Ai\Ai;
use Laravel\Ai\Providers\OpenAiProvider;
use Tests\TestCase;

class AimonProviderConfigurationTest extends TestCase
{
    public function test_aimon_provider_uses_openai_responses_with_the_expected_model(): void
    {
        $configuration = config('ai.providers.aimon');

        $this->assertSame('openai', $configuration['driver']);
        $this->assertSame('https://aimon.umb.ink/v1', $configuration['url']);
        $this->assertFalse($configuration['store']);
        $this->assertSame('gpt-5.6-sol', $configuration['models']['text']['default']);
        $this->assertSame('gpt-5.6-sol', $configuration['models']['text']['cheapest']);
        $this->assertSame('gpt-5.6-sol', $configuration['models']['text']['smartest']);

        $provider = Ai::textProvider('aimon');

        $this->assertInstanceOf(OpenAiProvider::class, $provider);
        $this->assertSame('gpt-5.6-sol', $provider->defaultTextModel());
    }
}
