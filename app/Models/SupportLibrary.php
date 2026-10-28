<?php

namespace App\Models;

use Database\Factories\SupportLibraryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportLibrary extends Model
{
    /** @use HasFactory<SupportLibraryFactory> */
    use HasFactory;

    protected $fillable = ['key', 'current_revision', 'current_version_id'];

    protected $attributes = ['current_revision' => 0];

    protected function casts(): array
    {
        return ['current_revision' => 'integer'];
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(SupportLibraryVersion::class, 'current_version_id');
    }
}
