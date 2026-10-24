<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['identity_key', 'client_id', 'user_id', 'batch_id', 'title', 'method', 'priority', 'status', 'invited', 'has_issues', 'published_date', 'registration_at', 'deadline_at', 'dates', 'payload', 'content_hash'])]
class ProcurementNotice extends Model
{
    use HasFactory;

    protected $attributes = ['invited' => false, 'has_issues' => false];

    protected function casts(): array
    {
        return ['payload' => 'array', 'dates' => 'array', 'invited' => 'boolean', 'has_issues' => 'boolean'];
    }
}
