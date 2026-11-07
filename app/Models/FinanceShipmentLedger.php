<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'title', 'display_title', 'source_key', 'orders', 'revision'])]
class FinanceShipmentLedger extends Model
{
    use HasFactory;

    protected $attributes = ['revision' => 0, 'orders' => '[]'];

    protected function casts(): array
    {
        return ['orders' => 'array', 'revision' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ObjectRecord::class, 'project_id');
    }
}
