<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'type',
    'anchor_at',
    'next_due_at',
    'last_triggered_at',
    'occurrences',
])]
class ProjectReminderState extends Model
{
    protected function casts(): array
    {
        return [
            'anchor_at' => 'datetime',
            'next_due_at' => 'datetime',
            'last_triggered_at' => 'datetime',
            'occurrences' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ObjectRecord::class, 'project_id');
    }
}
