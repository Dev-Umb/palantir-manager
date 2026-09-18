<?php

namespace App\Models;

use Database\Factories\FeishuUserBindingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'tenant_key', 'open_id', 'conversation_id', 'verified_at', 'disabled_at'])]
class FeishuUserBinding extends Model
{
    /** @use HasFactory<FeishuUserBindingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'disabled_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at')->whereNull('disabled_at');
    }
}
