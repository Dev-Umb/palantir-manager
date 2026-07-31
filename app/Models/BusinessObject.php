<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'label', 'group', 'code_prefix', 'title_field', 'fields', 'roles', 'read_only', 'sort_order'])]
class BusinessObject extends Model
{
    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $bindingField = $field ?? (ctype_digit((string) $value) ? $this->getKeyName() : 'key');

        return $this->newQuery()->where($bindingField, $value)->first();
    }

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'roles' => 'array',
            'read_only' => 'boolean',
        ];
    }

    public function records(): HasMany
    {
        return $this->hasMany(ObjectRecord::class);
    }
}
