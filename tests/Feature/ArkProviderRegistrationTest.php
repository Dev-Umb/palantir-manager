<?php

namespace Tests\Feature;

use Laravel\Ai\Ai;
use Laravel\Ai\Providers\OpenAiProvider;
use Tests\TestCase;

class ArkProviderRegistrationTest extends TestCase
{
    public function test_ark_provider_uses_the_compatibility_driver(): void
    {
        $provider = Ai::textProvider('ark');

        $this->assertInstanceOf(OpenAiProvider::class, $provider);
        $this->assertSame('ark-openai', $provider->driver());
        $this->assertFalse($provider->additionalConfiguration()['store']);
    }
}
