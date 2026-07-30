<?php

use App\Models\AiRun;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('ai.runs.{runId}', function (User $user, string $runId): bool {
    return $user->canDo('ai.harness.view')
        && AiRun::whereKey($runId)->where('user_id', $user->id)->exists();
});
