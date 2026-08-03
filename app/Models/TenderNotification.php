<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tender_id',
    'type',
    'deadline_type',
    'stage',
    'project_id',
    'user_id',
    'status',
    'deadline_at',
    'read_at',
    'resolved_at',
    'triggered_at',
    'occurrences',
])]
class TenderNotification extends Model
{
    public const TYPE_DEADLINE = 'deadline';

    public const TYPE_CONVERSION = 'conversion';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RESOLVED = 'resolved';

    protected function casts(): array
    {
        return [
            'deadline_at' => 'datetime',
            'read_at' => 'datetime',
            'resolved_at' => 'datetime',
            'triggered_at' => 'datetime',
            'occurrences' => 'integer',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(ObjectRecord::class, 'tender_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ObjectRecord::class, 'project_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
