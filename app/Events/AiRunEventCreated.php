<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AiRunEventCreated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public array $envelope) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('ai.runs.'.$this->envelope['run_id']);
    }

    public function broadcastAs(): string
    {
        return 'ai.run.event';
    }

    public function broadcastWith(): array
    {
        return $this->envelope;
    }
}
