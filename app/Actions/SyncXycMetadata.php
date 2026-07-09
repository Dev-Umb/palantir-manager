<?php

namespace App\Actions;

use App\Models\BusinessObject;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SyncXycMetadata
{
    public function handle(): void
    {
        $config = config('xyc');
        $now = now();

        Role::upsert(
            collect($config['roles'])->map(fn (array $role) => [
                'name' => $role['name'],
                'label' => $role['label'],
                'description' => $role['description'] ?? null,
                'locked' => $role['locked'] ?? false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['name'],
            ['label', 'description', 'locked', 'updated_at'],
        );

        $permissions = collect($config['permissions']);
        foreach ($config['objects'] as $object) {
            foreach (['view' => '查看', 'create' => '新建', 'update' => '编辑', 'delete' => '删除'] as $action => $label) {
                $permissions->push([
                    'key' => "object.{$object['key']}.{$action}",
                    'module' => $object['key'],
                    'action' => $action,
                    'label' => "{$label}{$object['label']}",
                ]);
            }
        }

        Permission::upsert(
            $permissions->map(fn (array $permission) => [
                'key' => $permission['key'],
                'module' => $permission['module'],
                'action' => $permission['action'],
                'label' => $permission['label'],
                'description' => $permission['description'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['key'],
            ['module', 'action', 'label', 'description', 'updated_at'],
        );

        BusinessObject::upsert(
            collect($config['objects'])->map(fn (array $object, int $index) => [
                'key' => $object['key'],
                'label' => $object['label'],
                'group' => $object['group'],
                'code_prefix' => $object['code_prefix'],
                'title_field' => $object['title_field'],
                'fields' => json_encode($object['fields'], JSON_UNESCAPED_UNICODE),
                'roles' => json_encode($object['roles'], JSON_UNESCAPED_UNICODE),
                'read_only' => $object['read_only'] ?? false,
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['key'],
            ['label', 'group', 'code_prefix', 'title_field', 'fields', 'roles', 'read_only', 'sort_order', 'updated_at'],
        );

        BusinessObject::whereNotIn('key', collect($config['objects'])->pluck('key'))->delete();
        Permission::whereNotIn('key', $permissions->pluck('key'))->delete();

        $permissionByKey = Permission::whereIn('key', $permissions->pluck('key'))->get()->keyBy('key')->toBase();

        $this->syncRolePermissions($permissionByKey);
    }

    /** @param Collection<string, Permission> $permissionByKey */
    private function syncRolePermissions(Collection $permissionByKey): void
    {
        $config = config('xyc');
        $objects = collect($config['objects']);
        $rows = [];

        foreach (Role::all() as $role) {
            $keys = $config['role_permissions'][$role->name] ?? [];

            if ($keys === ['*']) {
                foreach ($permissionByKey->pluck('id') as $permissionId) {
                    $rows[] = ['role_id' => $role->id, 'permission_id' => $permissionId];
                }

                continue;
            }

            $keys = collect($keys);
            foreach ($objects as $object) {
                $writeRoles = $object['write_roles'] ?? $object['roles'];

                if (in_array($role->name, $object['roles'], true) || in_array($role->name, $writeRoles, true)) {
                    $keys = $keys->push("object.{$object['key']}.view");
                }

                if (in_array($role->name, $writeRoles, true)) {
                    $keys = $keys->merge([
                        "object.{$object['key']}.create",
                        "object.{$object['key']}.update",
                        "object.{$object['key']}.delete",
                    ]);
                }
            }

            foreach ($permissionByKey->only($keys->unique()->all())->pluck('id') as $permissionId) {
                $rows[] = ['role_id' => $role->id, 'permission_id' => $permissionId];
            }
        }

        DB::table('permission_role')->delete();

        if ($rows) {
            DB::table('permission_role')->insert($rows);
        }
    }
}
