<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReimbursementBatchFile extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['id', 'batch_id', 'name', 'disk', 'object_key', 'size', 'mime', 'status', 'sha256', 'upload_id', 'draft_id', 'result', 'issues', 'reviewed', 'error'];

    protected $attributes = ['status' => 'uploading', 'reviewed' => false];

    protected function casts(): array
    {
        return ['size' => 'integer', 'result' => 'array', 'issues' => 'array', 'reviewed' => 'boolean'];
    }
}
