<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['prefix', 'sequence_date', 'last_number'])]
class CodeSequence extends Model
{
    protected function casts(): array
    {
        return [
            'sequence_date' => 'immutable_date',
            'last_number' => 'integer',
        ];
    }
}
