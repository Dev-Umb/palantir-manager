<?php

namespace App\Integrations\Feishu;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FeishuClient
{
    /** @return array{contents: string, mime_type: string} */
    public function downloadMessageResource(string $messageId, string $fileKey): array
    {
        $maxBytes = (int) config('services.feishu.attachment_max_bytes', 20 * 1024 * 1024);
        $response = $this->request()->withToken($this->tenantAccessToken())
            ->withOptions(['stream' => true])
            ->get('/im/v1/messages/'.rawurlencode($messageId).'/resources/'.rawurlencode($fileKey), [
                'type' => 'file',
            ]);
        if (! $response->successful()) {
            throw new RuntimeException("feishu_download_resource_failed:http={$response->status()}");
        }

        $declaredSize = (int) ($response->header('Content-Length') ?: 0);
        if ($declaredSize > $maxBytes) {
            throw new RuntimeException('feishu_attachment_size_invalid');
        }
        $body = $response->toPsrResponse()->getBody();
        $contents = '';
        while (! $body->eof()) {
            $contents .= $body->read(8192);
            if (strlen($contents) > $maxBytes) {
                throw new RuntimeException('feishu_attachment_size_invalid');
            }
        }

        return [
            'contents' => $contents,
            'mime_type' => (string) ($response->header('Content-Type') ?: 'application/octet-stream'),
        ];
    }

    /** @return array{message_id: string} */
    public function sendText(string $openId, string $text): array
    {
        return $this->sendMessage($openId, 'open_id', 'text', ['text' => $text]);
    }

    /** @return array{message_id: string} */
    public function sendTextToChat(string $chatId, string $text): array
    {
        return $this->sendMessage($chatId, 'chat_id', 'text', ['text' => $text]);
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array{message_id: string}
     */
    public function sendCard(string $openId, array $card): array
    {
        return $this->sendMessage($openId, 'open_id', 'interactive', $card);
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array{message_id: string}
     */
    public function sendCardToChat(string $chatId, array $card): array
    {
        return $this->sendMessage($chatId, 'chat_id', 'interactive', $card);
    }

    /** @return array{reaction_id: string} */
    public function addReaction(string $messageId, string $emojiType = 'Typing'): array
    {
        $response = $this->request()->withToken($this->tenantAccessToken())
            ->post('/im/v1/messages/'.rawurlencode($messageId).'/reactions', [
                'reaction_type' => ['emoji_type' => $emojiType],
            ]);
        $payload = $response->json();
        if (! $response->successful() || (int) ($payload['code'] ?? -1) !== 0) {
            throw new RuntimeException($this->errorMessage('add_reaction', $response->status(), $payload));
        }

        $reactionId = (string) data_get($payload, 'data.reaction_id');
        if ($reactionId === '') {
            throw new RuntimeException('feishu_reaction_id_missing');
        }

        return ['reaction_id' => $reactionId];
    }

    public function deleteReaction(string $messageId, string $reactionId): void
    {
        $response = $this->request()->withToken($this->tenantAccessToken())
            ->delete('/im/v1/messages/'.rawurlencode($messageId).'/reactions/'.rawurlencode($reactionId));
        $payload = $response->json();
        if (! $response->successful() || (int) ($payload['code'] ?? -1) !== 0) {
            throw new RuntimeException($this->errorMessage('delete_reaction', $response->status(), $payload));
        }
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array{message_id: string}
     */
    private function sendMessage(
        string $receiveId,
        string $receiveIdType,
        string $messageType,
        array $content,
    ): array {
        $response = $this->request()->withToken($this->tenantAccessToken())
            ->post('/im/v1/messages?receive_id_type='.rawurlencode($receiveIdType), [
                'receive_id' => $receiveId,
                'msg_type' => $messageType,
                'content' => json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        $payload = $response->json();
        if (! $response->successful() || (int) ($payload['code'] ?? -1) !== 0) {
            throw new RuntimeException($this->errorMessage('send_message', $response->status(), $payload));
        }

        return ['message_id' => (string) data_get($payload, 'data.message_id')];
    }

    /** @return array<string, string> */
    public function openIdsByEmails(array $emails): array
    {
        $response = $this->request()->withToken($this->tenantAccessToken())
            ->post('/contact/v3/users/batch_get_id?user_id_type=open_id', ['emails' => array_values($emails)]);
        $payload = $response->json();
        if (! $response->successful() || (int) ($payload['code'] ?? -1) !== 0) {
            throw new RuntimeException($this->errorMessage('resolve_user', $response->status(), $payload));
        }

        return collect(data_get($payload, 'data.user_list', []))
            ->filter(fn (array $user): bool => filled($user['email'] ?? null) && filled($user['user_id'] ?? null))
            ->mapWithKeys(fn (array $user): array => [(string) $user['email'] => (string) $user['user_id']])
            ->all();
    }

    private function tenantAccessToken(): string
    {
        $appId = (string) config('services.feishu.app_id');
        $appSecret = (string) config('services.feishu.app_secret');
        if ($appId === '' || $appSecret === '') {
            throw new RuntimeException('feishu_not_configured');
        }

        return Cache::remember('feishu:tenant-token:'.sha1($appId), now()->addMinutes(90), function () use ($appId, $appSecret): string {
            $response = $this->request()->post('/auth/v3/tenant_access_token/internal', [
                'app_id' => $appId,
                'app_secret' => $appSecret,
            ]);
            $payload = $response->json();
            if (! $response->successful() || (int) ($payload['code'] ?? -1) !== 0) {
                throw new RuntimeException($this->errorMessage('tenant_token', $response->status(), $payload));
            }

            $token = (string) ($payload['tenant_access_token'] ?? '');
            if ($token === '') {
                throw new RuntimeException('feishu_tenant_token_missing');
            }

            return $token;
        });
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl((string) config('services.feishu.base_url'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    private function errorMessage(string $operation, int $status, mixed $payload): string
    {
        $code = is_array($payload) ? (string) ($payload['code'] ?? 'unknown') : 'unknown';
        $message = is_array($payload) ? (string) ($payload['msg'] ?? 'request_failed') : 'request_failed';
        $message = mb_substr(preg_replace('/(token|secret|authorization)[^,;]*/i', '[redacted]', $message) ?: 'request_failed', 0, 300);

        return "feishu_{$operation}_failed:http={$status};code={$code};message={$message}";
    }
}
