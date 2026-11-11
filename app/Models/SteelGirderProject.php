<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SteelGirderProject extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $attributes = ['revision' => 1];

    protected $fillable = ['id', 'name', 'revision', 'created_by', 'updated_by', 'current_material_id'];

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(SteelGirderMaterial::class, 'current_material_id');
    }

    public function drawings(): HasMany
    {
        return $this->hasMany(SteelGirderDrawing::class, 'project_id');
    }
}
