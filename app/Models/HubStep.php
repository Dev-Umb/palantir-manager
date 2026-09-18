<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hub_run_id', 'stage', 'round', 'status', 'input', 'output', 'usage', 'attempts', 'error'])]
class HubStep extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'usage' => 'array',
        ];
    }
}
