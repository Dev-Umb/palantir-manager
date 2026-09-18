<?php

namespace App\Ai;

use App\Models\AiRun;
use App\Models\User;
use Illuminate\Support\Arr;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

class AiHistoryAuthorization
{
    public const MESSAGE = '当前权限已变化或旧对话的数据范围无法确认，请新建对话后重试。';

    public function allowsConversation(User $user, string $conversationId): bool
    {
        if (! Conversation::whereKey($conversationId)->where('user_id', $user->id)->exists()) {
            return false;
        }

        $runs = AiRun::where('conversation_id', $conversationId)->get();
        $messages = ConversationMessage::where('conversation_id', $conversationId);
        if (($runs->isEmpty() && (clone $messages)->exists())
            || ($runs->isNotEmpty() && (clone $messages)->where('created_at', '<', $runs->min('created_at'))->exists())) {
            return $this->canReadUnverifiedHistory($user);
        }

        return $runs->every(fn (AiRun $run): bool => $this->allowsRun($user, $run));
    }

    public function allowsRun(User $user, AiRun $run): bool
    {
        if ((string) $run->user_id !== (string) $user->id || ! $user->canDo('ai.harness.view')) {
            return false;
        }
        $previousPermissions = Arr::get($run->context_snapshot, 'actor.permissions', []);
        if (array_diff($previousPermissions, $user->permissionKeys()) !== []) {
            return false;
        }

        $provenance = $run->provenance ?? [];
        foreach ($run->events()->where('type', 'run.retrying')->get(['payload']) as $retry) {
            if (! array_key_exists('authorization_provenance', $retry->payload)) {
                if (! $this->canReadUnverifiedHistory($user)) {
                    return false;
                }
            } else {
                $provenance = [...$provenance, ...$retry->payload['authorization_provenance']];
            }
        }

        foreach ($provenance as $source) {
            $key = $source['object_key'] ?? '';
            if ($key === 'timebook') {
                if (! $user->canDo('timebook.view') || ! $user->canDo('timebook.ai.query')) {
                    return false;
                }

                continue;
            }
            if (! $user->canDo("object.{$key}.view")) {
                return false;
            }
            if (! array_key_exists('record_ids', $source) || ($source['record_ids_truncated'] ?? false)) {
                if (! $this->canReadUnverifiedHistory($user)) {
                    return false;
                }
            } elseif (! app(XycDataAccess::class)->canAccessSources($user, $key, $source['record_ids'])) {
                return false;
            }
        }

        if (! $run->provenance && collect($run->sources ?? [])->contains(fn (array $source): bool => ($source['object_key'] ?? '') !== 'business_objects')) {
            return $this->canReadUnverifiedHistory($user);
        }

        if (! $run->provenance && ! $previousPermissions
            && ($run->sources || $run->answer || $run->artifacts || $run->events()->whereIn('type', ['answer.delta', 'artifact.upsert'])->exists())) {
            return $this->canReadUnverifiedHistory($user);
        }

        return true;
    }

    private function canReadUnverifiedHistory(User $user): bool
    {
        return $user->canDo('ai.harness.view') && $user->canDo('object.project.view')
            && $user->roles()->where('name', 'admin')->exists();
    }
}
