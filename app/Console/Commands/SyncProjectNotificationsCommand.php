<?php

namespace App\Console\Commands;

use App\Actions\SyncProjectNotifications;
use Illuminate\Console\Command;

class SyncProjectNotificationsCommand extends Command
{
    protected $signature = 'xyc:sync-project-notifications';

    protected $description = '同步超过三个月项目的合同与回款站内通知';

    public function handle(SyncProjectNotifications $sync): int
    {
        $result = $sync->handle();

        $this->info(sprintf(
            '通知同步完成：新增 %d，重新激活 %d，解除 %d。',
            $result['created'],
            $result['reactivated'],
            $result['resolved'],
        ));

        return self::SUCCESS;
    }
}
