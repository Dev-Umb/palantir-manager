<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'business_object_id',
    'code',
    'title',
    'payload',
    'stock_dimension_key',
    'workflow_key',
    'workflow_target_roles',
    'workflow_seen_at',
    'workflow_seen_by',
    'created_by',
])]
class ObjectRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            return null;
        }

        return parent::resolveRouteBinding($value, $field);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'workflow_target_roles' => 'array',
            'workflow_seen_at' => 'datetime',
        ];
    }

    public function businessObject(): BelongsTo
    {
        return $this->belongsTo(BusinessObject::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
