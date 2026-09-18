<?php

namespace App\Http\Controllers;

use App\Integrations\Feishu\FeishuCallbackVerifier;
use App\Jobs\ProcessFeishuInboundEvent;
use App\Models\FeishuInboundEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeishuEventController extends Controller
{
    public function __invoke(Request $request, FeishuCallbackVerifier $verifier): JsonResponse
    {
        $payload = $request->json()->all();
        abort_unless($verifier->verify($payload), 403);

        if (filled($payload['challenge'] ?? null)) {
            return response()->json(['challenge' => $payload['challenge']]);
        }

        $eventId = (string) data_get($payload, 'header.event_id', '');
        $eventType = (string) data_get($payload, 'header.event_type', '');
        abort_if($eventId === '' || $eventType === '', 422);

        $event = FeishuInboundEvent::firstOrCreate(
            ['event_id' => $eventId],
            [
                'event_type' => $eventType,
                'tenant_key' => data_get($payload, 'header.tenant_key'),
                'sender_open_id' => data_get($payload, 'event.sender.sender_id.open_id'),
                'message_id' => data_get($payload, 'event.message.message_id'),
                'status' => 'received',
                'payload' => $payload,
            ],
        );
        if ($event->wasRecentlyCreated) {
            ProcessFeishuInboundEvent::dispatch($event->id)->afterCommit();
        }

        return response()->json(['code' => 0]);
    }
}
