<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'latest_version'])]
class Reimbursement extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'latest_version' => 'integer'];
    }
}
