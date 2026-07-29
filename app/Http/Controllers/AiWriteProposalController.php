<?php

namespace App\Http\Controllers;

use App\Actions\ConfirmAiWriteProposal;
use App\Ai\AiRunEventPublisher;
use App\Models\AiRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiWriteProposalController extends Controller
{
    public function confirm(
        Request $request,
        AiRun $run,
        string $proposal,
        ConfirmAiWriteProposal $proposals,
        AiRunEventPublisher $events,
    ): JsonResponse {
        $result = $proposals->confirm($run, $request->user(), $proposal);
        $events->publish($result['run'], 'artifact.upsert', ['artifact' => $result['artifact']]);

        return response()->json([
            'message' => $result['message'],
            'run' => $result['run']->refresh()->snapshot(),
        ], $result['ok'] ? 200 : 409);
    }

    public function reject(
        Request $request,
        AiRun $run,
        string $proposal,
        ConfirmAiWriteProposal $proposals,
        AiRunEventPublisher $events,
    ): JsonResponse {
        $result = $proposals->reject($run, $request->user(), $proposal);
        $events->publish($result['run'], 'artifact.upsert', ['artifact' => $result['artifact']]);

        return response()->json([
            'message' => $result['message'],
            'run' => $result['run']->refresh()->snapshot(),
        ]);
    }
}
