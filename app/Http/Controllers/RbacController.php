<?php

namespace App\Http\Controllers;

use App\Actions\DeleteRbacUser;
use App\Actions\UpdateRbacUserRoles;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RbacController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::query()->with('roles')->latest()->get();
        $adminCount = User::query()->whereHas('roles', fn ($query) => $query->where('name', 'admin'))->count();

        return Inertia::render('Rbac/Index', [
            'users' => $users->map(function (User $user) use ($request, $adminCount): array {
                $blockReason = match (true) {
                    $user->is($request->user()) => '不能删除当前登录账号',
                    $user->roles->contains('name', 'admin') && $adminCount <= 1 => '不能删除最后一个管理员',
                    default => null,
                };

                return [
                    ...$user->toArray(),
                    'can_delete' => $blockReason === null,
                    'delete_block_reason' => $blockReason,
                ];
            }),
            'roles' => Role::with('permissions')->orderByDesc('locked')->orderBy('label')->get(),
            'permissions' => Permission::orderBy('module')->orderBy('action')->get()->groupBy('module'),
        ]);
    }

    public function updateUserRoles(Request $request, User $user, UpdateRbacUserRoles $update): RedirectResponse
    {
        $data = $request->validate(['roles' => ['array'], 'roles.*' => ['integer', 'exists:roles,id']]);
        $update->handle($user, $data['roles'] ?? [], $request->user());

        return back()->with('status', '用户角色已更新。');
    }

    public function destroyUser(Request $request, User $user, DeleteRbacUser $delete): RedirectResponse
    {
        $delete->handle($user, $request->user());

        return back()->with('status', '用户已删除。');
    }

    public function updateRolePermissions(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->locked, 422, '内置管理角色不在原型里修改。');

        $data = $request->validate(['permissions' => ['array'], 'permissions.*' => ['integer', 'exists:permissions,id']]);
        $role->permissions()->sync($data['permissions'] ?? []);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'rbac.role_permissions.update',
            'subject_type' => Role::class,
            'subject_id' => (string) $role->id,
            'payload' => ['permissions' => $data['permissions'] ?? []],
        ]);

        return back()->with('status', '角色权限已更新。');
    }
}
