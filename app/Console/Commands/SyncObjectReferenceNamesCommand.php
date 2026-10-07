<?php

namespace App\Console\Commands;

use App\Actions\SyncObjectReferenceNames;
use App\Models\ObjectRecord;
use App\Support\ReferenceGraphLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncObjectReferenceNamesCommand extends Command
{
    protected $signature = 'xyc:sync-reference-names {--apply : 执行修复，默认仅预览}';

    protected $description = '预览或修复业务关联名称及派生显示字段，保留历史归档';

    public function handle(SyncObjectReferenceNames $sync, ReferenceGraphLock $lock): int
    {
        $apply = (bool) $this->option('apply');
        $changed = 0;
        $invalid = 0;
        DB::transaction(function () use ($sync, $lock, $apply, &$changed, &$invalid): void {
            $lock->acquire();
            $query = ObjectRecord::with('businessObject')->whereRelation('businessObject', fn ($objects) => $objects->whereIn('key', SyncObjectReferenceNames::OBJECT_KEYS))->orderBy('id');
            if ($apply) {
                $query->lockForUpdate();
            }
            foreach ($query->lazy(200) as $record) {
                $result = $sync->refreshRecord($record, $apply);
                if ($result['changed']) {
                    $changed++;
                    $this->line("{$record->businessObject->key} {$record->code} {$record->id}");
                }
                if ($result['invalid'] !== []) {
                    $invalid++;
                    $this->warn("无效引用 {$record->id}: ".implode(', ', $result['invalid']));
                }
            }
        });
        $this->info(($apply ? '修复完成' : '仅预览')."：变更 {$changed} 条，无效引用 {$invalid} 条。");

        return self::SUCCESS;
    }
}
