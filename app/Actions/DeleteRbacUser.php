<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteRbacUser
{
    public function handle(User $target, User $actor): void
    {
        if ($target->is($actor)) {
            throw ValidationException::withMessages([
                'user' => '不能删除当前登录账号。',
            ]);
        }

        DB::transaction(function () use ($target, $actor): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($target->id);
            $adminRole = Role::query()->where('name', 'admin')->lockForUpdate()->firstOrFail();
            $roles = $lockedUser->roles()->orderBy('roles.id')->get(['roles.id', 'roles.name']);

            if ($roles->contains('id', $adminRole->id) && $adminRole->users()->count() <= 1) {
                throw ValidationException::withMessages([
                    'user' => '不能删除最后一个管理员。',
                ]);
            }

            AuditLog::query()->create([
                'user_id' => $actor->id,
                'action' => 'rbac.user.delete',
                'subject_type' => User::class,
                'subject_id' => (string) $lockedUser->id,
                'payload' => [
                    'name' => $lockedUser->name,
                    'email' => $lockedUser->email,
                    'roles' => $roles->pluck('name')->all(),
                ],
            ]);

            $lockedUser->roles()->detach();
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $lockedUser->id)
                ->delete();
            $passwordBroker = (string) config('auth.defaults.passwords', 'users');
            DB::table((string) config("auth.passwords.{$passwordBroker}.table", 'password_reset_tokens'))
                ->where('email', $lockedUser->email)
                ->delete();
            $lockedUser->forceFill(['remember_token' => null])->save();
            $lockedUser->delete();
        });
    }
}
