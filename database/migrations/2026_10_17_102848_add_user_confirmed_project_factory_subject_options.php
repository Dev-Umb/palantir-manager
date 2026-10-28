<?php

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const SUBJECTS = ['文水县鑫源晟商贸有限公司', '恒鑫盛', '鑫辉', '精致诚', '恒泰机械', '山西弘创重工科技有限公司'];

    public function up(): void
    {
        $object = BusinessObject::query()->where('key', 'project')->first();
        if ($object === null) {
            return;
        }
        $fields = $object->fields;
        foreach ($fields as &$field) {
            if ($field['key'] === 'factory_subject') {
                $field['options'] = array_values(array_unique([...($field['options'] ?? []), ...self::SUBJECTS]));
            }
        }
        unset($field);
        $object->update(['fields' => $fields]);
    }

    public function down(): void
    {
        $object = BusinessObject::query()->where('key', 'project')->first();
        if ($object === null) {
            return;
        }
        $used = ObjectRecord::query()->where('business_object_id', $object->id)->get()->pluck('payload.factory_subject')->filter()->all();
        $unused = array_diff(self::SUBJECTS, $used);
        $fields = $object->fields;
        foreach ($fields as &$field) {
            if ($field['key'] === 'factory_subject') {
                $field['options'] = array_values(array_diff($field['options'] ?? [], $unused));
            }
        }
        unset($field);
        $object->update(['fields' => $fields]);
    }
};
