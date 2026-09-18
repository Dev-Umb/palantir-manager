<?php

namespace App\Support;

use App\Models\User;

class HubAccess
{
    public static function canRead(?User $user): bool
    {
        return $user?->roles->contains(fn ($role) => in_array($role->name, ['admin', 'tender', 'business'], true)) ?? false;
    }

    public static function canManage(?User $user): bool
    {
        return $user?->roles->contains('name', 'admin') ?? false;
    }
}
