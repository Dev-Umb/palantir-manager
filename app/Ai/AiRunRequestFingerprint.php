<?php

namespace App\Ai;

use JsonException;

class AiRunRequestFingerprint
{
    public function forRequest(string $message, ?string $conversationId, ?string $retryParentId): string
    {
        return $this->hash([
            'conversation_id' => $conversationId,
            'message' => trim($message),
            'retry_parent_id' => $retryParentId,
        ]);
    }

    /** @throws JsonException */
    public function hash(mixed $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
    }
}
