<?php

namespace App\Support;

use App\Models\User;

class QuotationAccess
{
    public static function allows(?User $user): bool
    {
        return $user?->roles->contains(fn ($role) => in_array($role->name, ['admin', 'business', 'business_manager'], true)) ?? false;
    }
}
