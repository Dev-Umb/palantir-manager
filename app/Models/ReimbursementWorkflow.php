<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['revision', 'definition'])]
class ReimbursementWorkflow extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['definition' => 'array', 'revision' => 'integer'];
    }
}
