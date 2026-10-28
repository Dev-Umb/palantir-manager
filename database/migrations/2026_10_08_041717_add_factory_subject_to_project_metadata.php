<?php

use App\Models\BusinessObject;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $object = BusinessObject::query()->where('key', 'project')->first();
        if ($object === null || collect($object->fields)->contains('key', 'factory_subject')) {
            return;
        }

        $fields = $object->fields;
        $index = collect($fields)->search(fn (array $field): bool => $field['key'] === 'name');
        array_splice($fields, $index === false ? count($fields) : $index + 1, 0, [[
            'key' => 'factory_subject',
            'label' => '工厂主体',
            'type' => 'select',
            'options' => ['江苏晋武', '山西鑫源昌'],
        ]]);
        $object->update(['fields' => $fields]);
    }

    public function down(): void
    {
        $object = BusinessObject::query()->where('key', 'project')->first();
        if ($object !== null) {
            $object->update(['fields' => array_values(array_filter(
                $object->fields,
                fn (array $field): bool => $field['key'] !== 'factory_subject',
            ))]);
        }
    }
};
