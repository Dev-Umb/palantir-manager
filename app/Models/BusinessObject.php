<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'label', 'group', 'code_prefix', 'title_field', 'fields', 'roles', 'read_only', 'sort_order'])]
class BusinessObject extends Model
{
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
