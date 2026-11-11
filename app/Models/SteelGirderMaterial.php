<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SteelGirderMaterial extends Model
{
    use HasFactory, HasUuids;

    protected $attributes = ['display_unit' => 't'];

    protected $fillable = ['project_id', 'source_attachment_id', 'parsed_payload', 'reviews', 'display_unit'];

    protected function casts(): array
    {
        return ['parsed_payload' => 'array', 'reviews' => 'array'];
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(StoredAttachment::class, 'source_attachment_id');
    }
}
