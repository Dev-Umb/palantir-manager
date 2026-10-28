<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['version_id', 'user_id', 'event', 'message', 'read_at', 'delivery_status', 'attempts', 'delivery_error', 'claimed_at'])]
class ReimbursementNotice extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'claimed_at' => 'datetime'];
    }
}
