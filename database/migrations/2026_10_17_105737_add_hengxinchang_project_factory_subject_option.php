<?php

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const SUBJECTS = ['恒鑫昌'];

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
