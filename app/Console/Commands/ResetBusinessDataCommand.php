<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetBusinessDataCommand extends Command
{
    protected $signature = 'xyc:reset-business-data {--force : Confirm destructive removal of business records}';

    protected $description = 'Clear business records while preserving accounts, RBAC, metadata, configuration, and attachments';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to reset business data without --force.');

            return self::FAILURE;
        }

        $counts = DB::transaction(function (): array {
            $objectKeys = BusinessObject::query()->orderBy('key')->pluck('key');
            $notificationCount = Schema::hasTable('project_notifications')
                ? DB::table('project_notifications')->count()
                : 0;
            $tenderNotificationCount = Schema::hasTable('tender_notifications')
                ? DB::table('tender_notifications')->count()
                : 0;
            $recordCount = ObjectRecord::count();
            $auditCount = AuditLog::whereIn('subject_type', $objectKeys)->count();
            $counts = [
                'object_records' => $recordCount,
                'audit_logs' => $auditCount,
                'project_notifications' => $notificationCount,
                'tender_notifications' => $tenderNotificationCount,
            ];

            if (Schema::hasTable('project_notifications')) {
                DB::table('project_notifications')->delete();
            }
            if (Schema::hasTable('tender_notifications')) {
                DB::table('tender_notifications')->delete();
            }

            AuditLog::whereIn('subject_type', $objectKeys)->delete();
            ObjectRecord::query()->delete();

            AuditLog::create([
                'user_id' => null,
                'action' => 'system.business_data.reset',
                'subject_type' => 'system',
                'subject_id' => null,
                'payload' => $counts,
            ]);

            return $counts;
        }, 3);

        $this->info('Business data reset completed.');
        $this->line(json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
