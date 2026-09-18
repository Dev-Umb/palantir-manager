<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'id', 'conversation_id', 'user_id', 'client_request_id', 'request_hash', 'retry_parent_id',
    'attempt_number', 'status', 'input', 'context_snapshot', 'answer', 'artifacts', 'sources',
    'provenance', 'data_quality', 'usage', 'error', 'failure_category', 'last_event_seq',
    'cancel_requested_at', 'cancel_reason', 'started_at', 'finished_at', 'origin', 'channel_context',
])]
class AiRun extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::updating(function (AiRun $run): void {
            if ($run->isDirty('context_snapshot')) {
                throw new LogicException('AI run context snapshots are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'artifacts' => 'array',
            'sources' => 'array',
            'provenance' => 'array',
            'context_snapshot' => 'array',
            'data_quality' => 'array',
            'usage' => 'array',
            'error' => 'array',
            'channel_context' => 'array',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AiRunEvent::class, 'run_id')->orderBy('seq');
    }

    public function retryParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_parent_id');
    }

    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_parent_id')->orderBy('attempt_number');
    }

    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'retry_parent_id' => $this->retry_parent_id,
            'attempt_number' => $this->attempt_number,
            'status' => $this->status,
            'input' => $this->input,
            'answer' => $this->answer ?? '',
            'artifacts' => $this->artifacts ?? [],
            'sources' => $this->sources ?? [],
            'provenance' => $this->provenance ?? [],
            'data_quality' => $this->data_quality ?? [],
            'error' => $this->error,
            'failure_category' => $this->failure_category,
            'cancel_reason' => $this->cancel_reason,
            'last_event_seq' => $this->last_event_seq,
            'created_at' => $this->created_at?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
        ];
    }
}
