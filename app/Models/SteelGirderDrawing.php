<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SteelGirderDrawing extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['id', 'project_id', 'stored_attachment_id', 'sha256'];

    protected function casts(): array
    {
        return [];
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(StoredAttachment::class, 'stored_attachment_id');
    }
}
