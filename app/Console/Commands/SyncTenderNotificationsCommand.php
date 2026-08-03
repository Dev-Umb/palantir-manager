<?php

namespace App\Console\Commands;

use App\Actions\SyncTenderNotifications;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('xyc:sync-tender-notifications')]
#[Description('同步招投标截止预警')]
class SyncTenderNotificationsCommand extends Command
{
    public function handle(SyncTenderNotifications $sync): int
    {
        $summary = $sync->handle();
        $this->info("created={$summary['created']} reactivated={$summary['reactivated']} resolved={$summary['resolved']}");

        return self::SUCCESS;
    }
}
