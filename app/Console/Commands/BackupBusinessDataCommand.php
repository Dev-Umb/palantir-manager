<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

class BackupBusinessDataCommand extends Command
{
    protected $signature = 'xyc:backup-business-data {--path= : Relative path on the local disk, under backups/}';

    protected $description = 'Create a readable gzip snapshot of business records before a production reset';

    public function handle(): int
    {
        try {
            $path = $this->backupPath((string) $this->option('path'));
            $snapshot = $this->snapshot();
            $json = json_encode(
                $snapshot,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $compressed = gzencode($json, 9);

            if ($compressed === false || ! Storage::disk('local')->put($path, $compressed)) {
                throw new RuntimeException("Unable to write business backup [{$path}].");
            }

            $this->info("Business backup created: {$path}");
            $this->line(json_encode($snapshot['counts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (JsonException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function backupPath(string $requested): string
    {
        $path = $requested !== ''
            ? trim(str_replace('\\', '/', $requested), '/')
            : 'backups/business-'.now()->format('Ymd-His-u').'.json.gz';

        if (! str_starts_with($path, 'backups/') || str_contains($path, '..') || ! str_ends_with($path, '.json.gz')) {
            throw new RuntimeException('Backup path must be a .json.gz file below backups/.');
        }

        return $path;
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $notifications = Schema::hasTable('project_notifications')
            ? DB::table('project_notifications')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()
            : [];

        $records = ObjectRecord::query()
            ->with('businessObject:id,key,label')
            ->orderBy('business_object_id')
            ->orderBy('code')
            ->get()
            ->map(fn (ObjectRecord $record) => [
                'id' => $record->id,
                'object_key' => $record->businessObject?->key,
                'object_label' => $record->businessObject?->label,
                'code' => $record->code,
                'title' => $record->title,
                'payload' => $record->payload,
                'created_by' => $record->created_by,
                'created_at' => $record->created_at?->toISOString(),
                'updated_at' => $record->updated_at?->toISOString(),
            ])
            ->values()
            ->all();

        $audits = AuditLog::query()
            ->whereIn('subject_type', BusinessObject::pluck('key'))
            ->orderBy('id')
            ->get()
            ->map(fn (AuditLog $audit) => [
                'id' => $audit->id,
                'user_id' => $audit->user_id,
                'action' => $audit->action,
                'subject_type' => $audit->subject_type,
                'subject_id' => $audit->subject_id,
                'payload' => $audit->payload,
                'created_at' => $audit->created_at?->toISOString(),
            ])
            ->values()
            ->all();

        return [
            'schema_version' => 1,
            'generated_at' => now()->toISOString(),
            'counts' => [
                'business_objects' => BusinessObject::count(),
                'object_records' => count($records),
                'audit_logs' => count($audits),
                'project_notifications' => count($notifications),
            ],
            'object_records' => $records,
            'audit_logs' => $audits,
            'project_notifications' => $notifications,
        ];
    }
}
