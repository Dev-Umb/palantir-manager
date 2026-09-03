<?php

namespace App\Models;

use Database\Factories\FeishuFileUploadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'inbound_event_id', 'binding_id', 'stored_attachment_id', 'conversation_key', 'file_key',
    'status', 'project_id', 'contract_id', 'attachment_field', 'attached_at', 'error',
])]
class FeishuFileUpload extends Model
{
    /** @use HasFactory<FeishuFileUploadFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ATTACHED = 'attached';

    protected function casts(): array
    {
        return ['attached_at' => 'datetime'];
    }

    public function inboundEvent(): BelongsTo
    {
        return $this->belongsTo(FeishuInboundEvent::class);
    }

    public function binding(): BelongsTo
    {
        return $this->belongsTo(FeishuUserBinding::class);
    }

    public function storedAttachment(): BelongsTo
    {
        return $this->belongsTo(StoredAttachment::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ObjectRecord::class, 'project_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ObjectRecord::class, 'contract_id');
    }
}
