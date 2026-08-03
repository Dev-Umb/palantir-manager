<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateRbacUserRoles
{
    /** @param array<int, int> $roleIds */
    public function handle(User $target, array $roleIds, User $actor): void
    {
        DB::transaction(function () use ($target, $roleIds, $actor): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($target->id);
            $adminRole = Role::query()->where('name', 'admin')->lockForUpdate()->firstOrFail();
            $removesAdmin = $lockedUser->roles()->whereKey($adminRole->id)->exists()
                && ! in_array($adminRole->id, $roleIds, true);

            if ($removesAdmin && $adminRole->users()->count() <= 1) {
                throw ValidationException::withMessages([
                    'roles' => '不能移除最后一个管理员的管理员角色。',
                ]);
            }

            $lockedUser->roles()->sync($roleIds);

            AuditLog::query()->create([
                'user_id' => $actor->id,
                'action' => 'rbac.user_roles.update',
                'subject_type' => User::class,
                'subject_id' => (string) $lockedUser->id,
                'payload' => ['roles' => $roleIds],
            ]);
        });
    }
}
