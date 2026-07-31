<?php

namespace App\Ai;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Throwable;

class AiFailureClassifier
{
    public function classify(Throwable $exception): array
    {
        $message = mb_strtolower($exception->getMessage());

        $failure = match (true) {
            str_contains($message, '429'), str_contains($message, 'rate limit') => $this->failure(
                'provider_rate_limited', 'provider_rate_limited', 'AI 服务繁忙，请稍后重试。', true,
            ),
            str_contains($message, '401'), str_contains($message, '403'), str_contains($message, 'api key') => $this->failure(
                'provider_auth', 'provider_auth', 'AI 服务配置无效，请联系管理员。', false,
            ),
            str_contains($message, 'timeout'), str_contains($message, 'timed out') => $this->failure(
                'provider_timeout', 'provider_timeout', 'AI 服务响应超时，请重试。', true,
            ),
            str_contains($message, 'connection'), str_contains($message, 'resolve host'), str_contains($message, 'network') => $this->failure(
                'network', 'provider_network_error', 'AI 服务网络连接失败，请稍后重试。', true,
            ),
            str_contains($message, 'validation'), str_contains($message, 'invalid request') => $this->failure(
                'invalid_request', 'provider_invalid_request', 'AI 请求无法执行，请调整问题后重试。', false,
            ),
            default => $this->failure(
                'provider_error', 'provider_error', 'AI 服务调用失败，请稍后重试。', true,
            ),
        };

        return [...$failure, ...$this->providerDiagnostics($exception)];
    }

    public function workerFailure(): array
    {
        return $this->failure(
            'worker_error', 'worker_failed', 'AI 任务执行超时或工作进程异常，请重试。', true,
        );
    }

    private function failure(string $category, string $code, string $message, bool $recoverable): array
    {
        return compact('category', 'code', 'message', 'recoverable');
    }

    private function providerDiagnostics(Throwable $exception): array
    {
        if (! $exception instanceof RequestException) {
            return [];
        }

        $excerpt = $this->sanitizeProviderResponse($exception->response->body());

        return array_filter([
            'provider_status' => $exception->response->status(),
            'provider_response_excerpt' => $excerpt,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function sanitizeProviderResponse(string $body): ?string
    {
        if (blank($body)) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $body = json_encode(
                $this->redactSensitiveValues($decoded),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            ) ?: '';
        }

        $sanitized = preg_replace([
            '/\bBearer\s+[A-Za-z0-9._~+\/=\-]+/iu',
            '/\b(?:sk|ak)-[A-Za-z0-9_-]{8,}\b/iu',
            '/((?:api[_-]?key|token|secret|password|authorization|credential)["\']?\s*[:=]\s*["\']?)[^"\'\s,;}\]]+/iu',
        ], [
            'Bearer [REDACTED]',
            '[REDACTED]',
            '$1[REDACTED]',
        ], $body) ?? '';

        $sanitized = Str::squish($sanitized);

        return $sanitized === '' ? null : mb_substr($sanitized, 0, 500);
    }

    private function redactSensitiveValues(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/password|secret|token|api[_-]?key|authorization|credential|cookie/iu', $key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item, string|int $itemKey): mixed => $this->redactSensitiveValues(
                    $item,
                    is_string($itemKey) ? $itemKey : null,
                ))
                ->all();
        }

        if (! is_string($value)) {
            return $value;
        }

        return preg_replace([
            '/\bBearer\s+[A-Za-z0-9._~+\/=\-]+/iu',
            '/\b(?:sk|ak)-[A-Za-z0-9_-]{8,}\b/iu',
        ], [
            'Bearer [REDACTED]',
            '[REDACTED]',
        ], $value) ?? '';
    }
}
