<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'hub_notice_id', 'hub_source_id', 'kind', 'query', 'status', 'stage', 'round', 'snapshot', 'result', 'audit', 'score', 'published_at', 'stale_at', 'cancelled_at', 'error'])]
class HubRun extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'result' => 'array',
            'audit' => 'array',
            'score' => 'array',
            'published_at' => 'datetime',
            'stale_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(HubStep::class);
    }
}
