<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReimbursementDraft extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'user_id', 'payload', 'revision', 'version_id', 'batch_id'];

    protected $attributes = ['revision' => 0];

    protected function casts(): array
    {
        return ['payload' => 'array', 'user_id' => 'integer', 'revision' => 'integer', 'version_id' => 'integer'];
    }
}
