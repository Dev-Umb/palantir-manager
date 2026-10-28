<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InstallSupportLibraryPermissions extends Command
{
    protected $signature = 'support-library:install-permissions';

    protected $description = '增量安装技术人员支架检索权限，保留其他授权';

    public function handle(): int
    {
        DB::transaction(function (): void {
            $ids = [];
            foreach (config('xyc.permissions') as $permission) {
                if (str_starts_with($permission['key'], 'support-library.')) {
                    $ids[] = Permission::updateOrCreate(['key' => $permission['key']], $permission)->id;
                }
            }
            foreach (Role::whereIn('name', ['engineering', 'admin'])->get() as $role) {
                $role->permissions()->syncWithoutDetaching($ids);
            }
        });
        $this->info('支架检索权限已安装，其他角色授权保持不变。');

        return self::SUCCESS;
    }
}
