<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'type',
    'user_id',
    'status',
    'read_at',
    'resolved_at',
    'triggered_at',
    'occurrences',
])]
class ProjectNotification extends Model
{
    public const TYPE_CONTRACT = 'contract';

    public const TYPE_PAYMENT = 'payment';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RESOLVED = 'resolved';

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'resolved_at' => 'datetime',
            'triggered_at' => 'datetime',
            'occurrences' => 'integer',
        ];
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
