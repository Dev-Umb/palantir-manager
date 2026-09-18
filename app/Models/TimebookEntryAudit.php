<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimebookEntryAudit extends Model
{
    protected $fillable = ['entry_id', 'user_id', 'actor_name', 'action', 'before', 'after'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }
}
