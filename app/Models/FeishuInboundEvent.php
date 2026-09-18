<?php

namespace App\Models;

use Database\Factories\FeishuInboundEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id', 'event_type', 'tenant_key', 'sender_open_id', 'message_id', 'binding_id',
    'ai_run_id', 'status', 'payload', 'reply_message_id', 'processing_reaction_id',
    'processing_reaction_removed_at', 'processing_reaction_error', 'error', 'processed_at',
])]
class FeishuInboundEvent extends Model
{
    /** @use HasFactory<FeishuInboundEventFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processing_reaction_removed_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function binding(): BelongsTo
    {
        return $this->belongsTo(FeishuUserBinding::class, 'binding_id');
    }

    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }
}
