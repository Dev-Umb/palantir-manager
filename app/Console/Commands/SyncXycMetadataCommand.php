<?php

namespace App\Console\Commands;

use App\Actions\SyncXycMetadata;
use Illuminate\Console\Command;

class SyncXycMetadataCommand extends Command
{
    protected $signature = 'xyc:sync-metadata';

    protected $description = 'Synchronize XYC metadata and RBAC without importing reference snapshots';

    public function handle(SyncXycMetadata $sync): int
    {
        $sync->handle();

        $this->info('XYC metadata and RBAC synchronized.');

        return self::SUCCESS;
    }
}
