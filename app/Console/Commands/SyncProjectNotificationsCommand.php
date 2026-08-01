<?php

namespace App\Console\Commands;

use App\Actions\SyncProjectNotifications;
use Illuminate\Console\Command;

class SyncProjectNotificationsCommand extends Command
{
    protected $signature = 'xyc:sync-project-notifications';

    protected $description = '同步项目投标、加工函、合同签署与回款周期提醒';

    public function handle(SyncProjectNotifications $sync): int
    {
        $result = $sync->handle();

        $this->info(sprintf(
            '通知同步完成：触发周期 %d，新增 %d，重新激活 %d，解除 %d。',
            $result['triggered'],
            $result['created'],
            $result['reactivated'],
            $result['resolved'],
        ));

        return self::SUCCESS;
    }
}
