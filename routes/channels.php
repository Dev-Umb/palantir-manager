<?php

use App\Ai\AiHistoryAuthorization;
use App\Models\AiRun;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('ai.runs.{runId}', function (User $user, string $runId): bool {
    $run = AiRun::find($runId);

    return $run && app(AiHistoryAuthorization::class)->allowsRun($user, $run);
});
