<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['reimbursement_id', 'user_id', 'number', 'submission_key', 'submission_fingerprint', 'title', 'type', 'note', 'items', 'total_cents', 'flow', 'cc', 'decisions', 'node_index', 'status', 'wechat_subscribed', 'wechat_binding_version'])]
class ReimbursementVersion extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['items' => 'array', 'flow' => 'array', 'cc' => 'array', 'decisions' => 'array', 'wechat_subscribed' => 'boolean', 'total_cents' => 'integer', 'number' => 'integer', 'node_index' => 'integer'];
    }
}
