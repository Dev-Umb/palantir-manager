<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id', 'conversation_id', 'user_id', 'client_request_id', 'status', 'input', 'answer',
    'artifacts', 'sources', 'data_quality', 'usage', 'error', 'last_event_seq', 'cancel_requested_at',
    'started_at', 'finished_at',
])]
class AiRun extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'artifacts' => 'array',
            'sources' => 'array',
            'data_quality' => 'array',
            'usage' => 'array',
            'error' => 'array',
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

    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'status' => $this->status,
            'input' => $this->input,
            'answer' => $this->answer ?? '',
            'artifacts' => $this->artifacts ?? [],
            'sources' => $this->sources ?? [],
            'data_quality' => $this->data_quality ?? [],
            'error' => $this->error,
            'last_event_seq' => $this->last_event_seq,
            'created_at' => $this->created_at?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
        ];
    }
}
