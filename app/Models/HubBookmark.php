<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'hub_notice_id', 'seen_at'])]
class HubBookmark extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'seen_at' => 'datetime',
        ];
    }
}
