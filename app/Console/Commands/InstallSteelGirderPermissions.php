<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InstallSteelGirderPermissions extends Command
{
    protected $signature = 'steel-girder-projects:install-permissions';

    protected $description = '增量安装技术人员钢箱梁项目权限，保留其他授权';

    public function handle(): int
    {
        DB::transaction(function (): void {
            $ids = [];
            foreach (config('xyc.permissions') as $permission) {
                if (str_starts_with($permission['key'], 'steel-girder-projects.')) {
                    $ids[] = Permission::updateOrCreate(['key' => $permission['key']], $permission)->id;
                }
            }
            foreach (Role::whereIn('name', ['engineering', 'admin'])->get() as $role) {
                $role->permissions()->syncWithoutDetaching($ids);
            }
        });
        $this->info('钢箱梁项目权限已安装，其他角色授权保持不变。');

        return self::SUCCESS;
    }
}
