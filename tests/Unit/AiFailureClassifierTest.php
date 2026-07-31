<?php

namespace Tests\Unit;

use App\Ai\AiFailureClassifier;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AiFailureClassifierTest extends TestCase
{
    public function test_provider_http_diagnostics_are_bounded_and_sanitized(): void
    {
        $exception = $this->requestException(400, json_encode([
            'error' => [
                'code' => 'InvalidParameter',
                'api_key' => 'ak-example-secret-value',
                'token' => 'token-secret-value',
                'nested' => ['password' => 'password-secret-value'],
                'hint' => 'Authorization: Bearer bearer-secret-value',
                'message' => 'temporary rejection '.str_repeat('x', 700),
            ],
        ], JSON_THROW_ON_ERROR));

        $failure = (new AiFailureClassifier)->classify($exception);

        $this->assertSame(400, $failure['provider_status']);
        $this->assertLessThanOrEqual(500, mb_strlen($failure['provider_response_excerpt']));
        $this->assertStringContainsString('InvalidParameter', $failure['provider_response_excerpt']);
        $this->assertStringContainsString('[REDACTED]', $failure['provider_response_excerpt']);
        $this->assertStringNotContainsString('ak-example-secret-value', $failure['provider_response_excerpt']);
        $this->assertStringNotContainsString('token-secret-value', $failure['provider_response_excerpt']);
        $this->assertStringNotContainsString('password-secret-value', $failure['provider_response_excerpt']);
        $this->assertStringNotContainsString('bearer-secret-value', $failure['provider_response_excerpt']);
    }

    public function test_non_http_failures_do_not_gain_provider_response_fields(): void
    {
        $failure = (new AiFailureClassifier)->classify(new RuntimeException('connection refused'));

        $this->assertSame('network', $failure['category']);
        $this->assertArrayNotHasKey('provider_status', $failure);
        $this->assertArrayNotHasKey('provider_response_excerpt', $failure);
    }

    private function requestException(int $status, string $body): RequestException
    {
        return new RequestException(new Response(new PsrResponse($status, [], $body)));
    }
}
