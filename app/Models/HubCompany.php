<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kind', 'name', 'data', 'status', 'confirmed_by', 'confirmed_at', 'revision'])]
class HubCompany extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }
}
