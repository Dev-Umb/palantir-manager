<?php

namespace App\Ai;

use App\Models\BusinessObject;
use App\Models\User;

class AiRunContextFactory
{
    public function __construct(private AiRunRequestFingerprint $fingerprint) {}

    public function make(User $user, string $conversationId, ?string $retryParentId, int $attemptNumber): array
    {
        $permissions = collect($user->permissionKeys())->sort()->values();
        $roles = $user->roles()->pluck('name')->sort()->values();
        $visibleObjects = BusinessObject::query()->orderBy('key')->get()
            ->filter(fn (BusinessObject $object) => $permissions->contains("object.{$object->key}.view"))
            ->pluck('key')->values();
        $provider = (string) config('ai.default', 'ark');

        return [
            'schema_version' => 1,
            'captured_at' => now()->toISOString(),
            'actor' => [
                'user_id' => $user->id,
                'roles' => $roles->all(),
                'permissions' => $permissions->all(),
                'visible_objects' => $visibleObjects->all(),
            ],
            'request' => [
                'conversation_id' => $conversationId,
                'retry_parent_id' => $retryParentId,
                'attempt_number' => $attemptNumber,
            ],
            'runtime' => [
                'provider' => $provider,
                'model' => (string) config("ai.providers.{$provider}.models.text.default", ''),
                'metrics_version' => $this->fingerprint->hash(config('ai_metrics', [])),
            ],
        ];
    }
}
