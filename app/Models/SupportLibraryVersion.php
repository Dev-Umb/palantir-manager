<?php

namespace App\Models;

use Database\Factories\SupportLibraryVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportLibraryVersion extends Model
{
    /** @use HasFactory<SupportLibraryVersionFactory> */
    use HasFactory;

    public const RULE_VERSION = 'support-grid-20261013-v1';

    public $timestamps = false;

    protected $fillable = ['library_id', 'revision', 'schema_version', 'rule_version', 'payload_json', 'payload_sha256', 'source_filename', 'source_sha256', 'actor_id', 'action', 'request_id', 'input_sha256', 'created_at'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'payload_json' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('支架库历史版本不可修改。'));
        static::deleting(fn () => throw new \LogicException('支架库历史版本不可删除。'));
    }

    public function envelope(): array
    {
        return ['revision' => $this->revision, 'rule_version' => $this->rule_version, 'updated_at' => $this->created_at->toIso8601String(),
            'source_filename' => $this->source_filename, 'hash' => $this->payload_sha256, 'library' => $this->payload_json];
    }
}
