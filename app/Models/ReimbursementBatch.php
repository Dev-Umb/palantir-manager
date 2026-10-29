<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReimbursementBatch extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['id', 'user_id', 'status'];

    protected $attributes = ['status' => 'uploading'];

    protected function casts(): array
    {
        return ['user_id' => 'integer'];
    }
}
