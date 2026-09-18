<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'url', 'allowed_hosts', 'enabled', 'status', 'adapter', 'interval_minutes', 'keywords', 'last_checked_at', 'last_success_at', 'last_error'])]
class HubSource extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'allowed_hosts' => 'array',
            'keywords' => 'array',
            'enabled' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }
}
