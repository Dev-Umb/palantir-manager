<?php

namespace App\Models;

use Database\Factories\StoredAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['logical_path', 'disk', 'object_key', 'original_name', 'mime_type', 'size', 'sha256', 'status'])]
class StoredAttachment extends Model
{
    /** @use HasFactory<StoredAttachmentFactory> */
    use HasFactory;

    use HasUuids;

    public const STATUS_STAGED = 'staged';

    public const STATUS_ATTACHED = 'attached';
}
