<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'stored_attachment_id', 'status', 'extraction', 'review', 'result', 'error', 'confirmed_at'])]
class AiContractIntake extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['extraction' => 'array', 'review' => 'array', 'result' => 'array', 'confirmed_at' => 'datetime'];
    }

    public function storedAttachment(): BelongsTo
    {
        return $this->belongsTo(StoredAttachment::class);
    }
}
