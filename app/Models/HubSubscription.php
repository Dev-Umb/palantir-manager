<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'name', 'filters', 'seen_at'])]
class HubSubscription extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'seen_at' => 'datetime',
        ];
    }
}
