<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReimbursementDraft extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'user_id', 'payload', 'revision', 'version_id', 'batch_id'];

    protected $attributes = ['revision' => 0];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime', 'payload' => 'array', 'user_id' => 'integer', 'revision' => 'integer', 'version_id' => 'integer'];
    }
}
