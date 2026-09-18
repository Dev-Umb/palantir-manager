<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['identity_key', 'project_key', 'title', 'buyer', 'group_name', 'group_confirmed', 'project_code', 'lot', 'round', 'kind', 'region', 'product', 'published_at', 'deadline', 'facts', 'missing', 'revision'])]
class HubNotice extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'missing' => 'array',
            'group_confirmed' => 'boolean',
            'published_at' => 'datetime',
            'deadline' => 'datetime',
        ];
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(HubEvidence::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(HubRun::class);
    }
}
