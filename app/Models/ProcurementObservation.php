<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['batch_id', 'notice_id', 'item_id', 'status', 'payload', 'warnings'])]
class ProcurementObservation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['payload' => 'array', 'warnings' => 'array'];
    }
}
