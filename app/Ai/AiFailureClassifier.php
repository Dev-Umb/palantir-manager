<?php

namespace App\Ai;

use Throwable;

class AiFailureClassifier
{
    public function classify(Throwable $exception): array
    {
        $message = mb_strtolower($exception->getMessage());

        return match (true) {
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
}
