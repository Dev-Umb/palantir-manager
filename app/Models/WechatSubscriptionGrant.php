<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'template_key', 'template_id', 'available_count', 'last_accepted_at'])]
class WechatSubscriptionGrant extends Model
{
    protected function casts(): array
    {
        return ['available_count' => 'integer', 'last_accepted_at' => 'datetime'];
    }
}
