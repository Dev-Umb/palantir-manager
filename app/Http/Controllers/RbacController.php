<?php

namespace App\Http\Controllers;

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
    public function index(): Response
    {
        return Inertia::render('Rbac/Index', [
            'users' => User::with('roles')->latest()->get(),
            'roles' => Role::with('permissions')->orderByDesc('locked')->orderBy('label')->get(),
            'permissions' => Permission::orderBy('module')->orderBy('action')->get()->groupBy('module'),
        ]);
    }

    public function updateUserRoles(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['roles' => ['array'], 'roles.*' => ['integer', 'exists:roles,id']]);
        $user->roles()->sync($data['roles'] ?? []);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'rbac.user_roles.update',
            'subject_type' => User::class,
            'subject_id' => (string) $user->id,
            'payload' => ['roles' => $data['roles'] ?? []],
        ]);

        return back()->with('status', '用户角色已更新。');
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
