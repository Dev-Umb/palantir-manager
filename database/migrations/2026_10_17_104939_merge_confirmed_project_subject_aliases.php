<?php

use App\Models\AuditLog;
use App\Models\BusinessObject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ALIASES = ['山西鑫源昌' => '文水鑫源昌', '山西弘创重工科技有限公司' => '山西弘创重工', '山西弘创重工有限公司' => '山西弘创重工', '山西汾阳丰源网架钢结构有限公司' => '山西汾阳丰源网架钢结构', '文水县恒鑫达贸易有限公司' => '文水县恒鑫达贸易', '文水县鑫源晟商贸有限公司' => '文水县鑫源晟商贸'];

    public function up(): void
    {
        DB::transaction(function (): void {
            $object = BusinessObject::query()->where('key', 'project')->lockForUpdate()->first();
            if ($object === null) {
                return;
            }
            foreach (DB::table('object_records')->where('business_object_id', $object->id)->lockForUpdate()->get() as $record) {
                $payload = json_decode($record->payload, true, 512, JSON_THROW_ON_ERROR);
                $before = $payload['factory_subject'] ?? null;
                if (! isset(self::ALIASES[$before ?? ''])) {
                    continue;
                }
                $payload['factory_subject'] = self::ALIASES[$before];
                DB::table('object_records')->where('id', $record->id)->update(['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                AuditLog::create(['action' => 'project.factory_subject.merge_confirmed_alias', 'subject_type' => 'object_record', 'subject_id' => $record->id, 'payload' => ['before' => $before, 'after' => $payload['factory_subject'], 'reason' => '用户确认弘创合并、文水鑫源昌显示名及去掉公司类型后缀']]);
            }
            $fields = $object->fields;
            foreach ($fields as &$field) {
                if ($field['key'] === 'factory_subject') {
                    $field['options'] = array_values(array_unique(array_map(fn (string $value): string => self::ALIASES[$value] ?? $value, $field['options'] ?? [])));
                }
            }
            unset($field);
            $object->update(['fields' => $fields]);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('已确认主体合并不能自动拆分；如需撤销，请按备份及审计记录执行前向修复。');
    }
};
