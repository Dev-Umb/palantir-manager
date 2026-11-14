<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InstallReimbursementPermissions extends Command
{
    protected $signature = 'reimbursements:install-permissions';

    protected $description = '增量安装报销权限，管理员配置流程后启用提交';

    public function handle(): int
    {
        DB::transaction(function (): void {
            $cashier = Role::firstOrCreate(['name' => 'cashier'], ['label' => '出纳', 'description' => '只读查看报销并接收审批完成知会。', 'locked' => true]);
            foreach (config('xyc.permissions') as $definition) {
                if (! str_starts_with($definition['key'], 'reimbursements.')) {
                    continue;
                }
                $permission = Permission::updateOrCreate(['key' => $definition['key']], $definition);
                if ($definition['action'] === 'manage') {
                    $cashier->permissions()->syncWithoutDetaching([$permission->id]);
                }
                $roles = $definition['action'] === 'submit' ? ['admin', 'business'] : ['admin'];
                foreach (Role::whereIn('name', $roles)->get() as $role) {
                    $role->permissions()->syncWithoutDetaching([$permission->id]);
                }
            }
        });
        $this->info('报销权限已安装。请管理员配置实际审批人，不自动发布默认流程。');

        return self::SUCCESS;
    }
}
