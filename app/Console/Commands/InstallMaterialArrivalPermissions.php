<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InstallMaterialArrivalPermissions extends Command
{
    protected $signature = 'material-arrivals:install-permissions';

    protected $description = '增量安装原材进场权限，仅默认授予管理角色，不改其他授权';

    public function handle(): int
    {
        DB::transaction(function (): void {
            $ids = [];
            foreach (config('xyc.permissions') as $permission) {
                if (str_starts_with($permission['key'], 'material-arrivals.')) {
                    $ids[] = Permission::updateOrCreate(['key' => $permission['key']], $permission)->id;
                }
            }
            Role::where('name', 'admin')->first()?->permissions()->syncWithoutDetaching($ids);
        });
        $this->info('原材进场权限已安装；其他人员可通过现有权限管理分配。');

        return self::SUCCESS;
    }
}
