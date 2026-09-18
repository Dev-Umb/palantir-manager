<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'rows', 'errors', 'confirmed_at'])]
class HubImport extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'rows' => 'array',
            'errors' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }
}
