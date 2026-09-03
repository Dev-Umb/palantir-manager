<?php

namespace App\Integrations\Feishu;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

class FeishuCliClient
{
    /** @return array{title: string, type: string, url: string, token: string|null} */
    public function createDocument(string $title, string $xml, string $recipientOpenId): array
    {
        $payload = $this->run(
            ['docs', '+create', '--as', 'bot', '--content', '-', '--json'],
            $xml,
        );

        $file = $this->fileResult($payload, $title, 'docx');
        $this->grantViewPermission($file, $recipientOpenId);

        return $file;
    }

    /**
     * @param  string[]  $headers
     * @param  array<int, array<int, scalar|null>>  $rows
     * @return array{title: string, type: string, url: string, token: string|null}
     */
    public function createSpreadsheet(string $title, array $headers, array $rows, string $recipientOpenId): array
    {
        $payload = $this->run([
            'sheets', '+workbook-create', '--as', 'bot', '--title', $title,
            '--headers', json_encode($headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            '--values', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            '--json',
        ]);

        $file = $this->fileResult($payload, $title, 'sheet');
        $this->grantViewPermission($file, $recipientOpenId);

        return $file;
    }

    /** @param array{title: string, type: string, url: string, token: string|null} $file */
    private function grantViewPermission(array $file, string $recipientOpenId): void
    {
        if (blank($file['token'])) {
            throw new RuntimeException('feishu_cli_file_token_missing');
        }

        $this->run([
            'drive', 'permission.members', 'create', '--as', 'bot',
            '--token', $file['token'], '--type', $file['type'],
            '--data', json_encode([
                'member_type' => 'openid',
                'member_id' => $recipientOpenId,
                'perm' => 'view',
                'type' => 'user',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            '--yes', '--json',
        ]);
    }

    /** @return array<string, mixed> */
    private function run(array $arguments, ?string $input = null): array
    {
        $command = [(string) config('services.feishu.cli.binary', 'lark-cli')];
        $profile = trim((string) config('services.feishu.cli.profile'));
        if ($profile !== '') {
            array_push($command, '--profile', $profile);
        }
        array_push($command, ...$arguments);

        $pending = Process::timeout((int) config('services.feishu.cli.timeout', 45));
        if ($input !== null) {
            $pending->input($input);
        }

        $result = $pending->run($command);
        if ($result->failed()) {
            throw new RuntimeException($this->failureMessage($result));
        }

        $payload = $this->decodeEnvelope($result->output());
        if (! ($payload['ok'] ?? false)) {
            $type = (string) data_get($payload, 'error.type', 'request_failed');
            $message = (string) data_get($payload, 'error.message', '飞书 CLI 执行失败');

            throw new RuntimeException('feishu_cli_failed:'.$this->sanitize("{$type}:{$message}"));
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function decodeEnvelope(string $output): array
    {
        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new RuntimeException('feishu_cli_invalid_response');
        }

        $payload = json_decode(substr($output, $start, $end - $start + 1), true);
        if (! is_array($payload)) {
            throw new RuntimeException('feishu_cli_invalid_response');
        }

        return $payload;
    }

    /** @return array{title: string, type: string, url: string, token: string|null} */
    private function fileResult(array $payload, string $fallbackTitle, string $type): array
    {
        $flat = Arr::dot($payload);
        $url = collect($flat)->first(fn (mixed $value, string $key): bool => is_string($value) && Str::contains($key, ['url', 'link']) && Str::startsWith($value, 'https://')
        );
        $token = collect($flat)->first(fn (mixed $value, string $key): bool => is_string($value) && Str::contains($key, ['token', 'document_id', 'spreadsheet_token'])
        );
        $title = collect($flat)->first(fn (mixed $value, string $key): bool => is_string($value) && Str::endsWith($key, 'title')
        );

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('feishu_cli_file_url_missing');
        }

        return [
            'title' => is_string($title) && $title !== '' ? $title : $fallbackTitle,
            'type' => $type,
            'url' => $url,
            'token' => is_string($token) && $token !== '' ? $token : null,
        ];
    }

    private function failureMessage(ProcessResult $result): string
    {
        $message = trim($result->errorOutput()) ?: trim($result->output());

        return 'feishu_cli_process_failed:exit='.$result->exitCode().';message='.$this->sanitize($message);
    }

    private function sanitize(string $message): string
    {
        $sanitized = preg_replace(
            '/(app[_ -]?secret|access[_ -]?token|authorization|device[_ -]?code)[^,;\s]*/i',
            '[redacted]',
            $message,
        ) ?: 'request_failed';

        return Str::limit(Str::squish($sanitized), 300, '');
    }
}
