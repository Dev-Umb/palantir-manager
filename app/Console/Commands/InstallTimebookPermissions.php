<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('timebook:install-permissions')]
#[Description('Install only timebook permissions without resetting existing role grants')]
class InstallTimebookPermissions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        DB::transaction(function (): void {
            $ids = [];
            foreach (config('xyc.permissions') as $permission) {
                if (str_starts_with($permission['key'], 'timebook.')) {
                    $ids[] = Permission::updateOrCreate(['key' => $permission['key']], $permission)->id;
                }
            }
            Role::where('name', 'admin')->first()?->permissions()->syncWithoutDetaching($ids);
        });
        $this->info('工日簿权限已安装；其他角色授权保持不变。');

        return self::SUCCESS;
    }
}
