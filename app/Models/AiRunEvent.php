<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['run_id', 'seq', 'type', 'payload', 'created_at'])]
class AiRunEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'run_id');
    }

    public function envelope(): array
    {
        return [
            'version' => 1,
            'run_id' => $this->run_id,
            'seq' => $this->seq,
            'type' => $this->type,
            'payload' => $this->payload,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
