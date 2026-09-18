<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hub_notice_id', 'hub_source_id', 'url', 'url_hash', 'content_hash', 'text', 'raw_path', 'extraction', 'attachments', 'fetched_at'])]
class HubEvidence extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'extraction' => 'array',
            'attachments' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    protected $table = 'hub_evidence';

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('Evidence is immutable.');
        });
    }
}
