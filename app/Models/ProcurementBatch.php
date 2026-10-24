<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'client_id', 'payload_hash', 'idempotency_key', 'payload', 'results', 'status', 'expires_at'])]
class ProcurementBatch extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = ['status' => 'validated'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'results' => 'array', 'expires_at' => 'datetime'];
    }
}
