<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('object_records', function (Blueprint $table) {
            $table->string('stock_dimension_key', 64)->nullable()->after('payload');
        });

        $ledgerObjectId = DB::table('business_objects')->where('key', 'stock_ledger')->value('id');
        if ($ledgerObjectId) {
            $keepers = [];
            $records = DB::table('object_records')
                ->where('business_object_id', $ledgerObjectId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'payload']);

            foreach ($records as $record) {
                $payload = is_array($record->payload)
                    ? $record->payload
                    : json_decode((string) $record->payload, true);
                $materialId = is_array($payload) ? ($payload['material_id'] ?? null) : null;
                if (! is_string($materialId) || $materialId === '') {
                    continue;
                }

                $dimension = [
                    'material_id' => $materialId,
                    'material_model' => trim((string) ($payload['material_model'] ?? '')),
                    'spec' => trim((string) ($payload['spec'] ?? '')),
                    'bin' => trim((string) ($payload['bin'] ?? '')),
                ];
                $key = hash('sha256', json_encode(
                    $dimension,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ));

                if (isset($keepers[$key])) {
                    DB::table('object_records')->where('id', $record->id)->delete();

                    continue;
                }

                $keepers[$key] = $record->id;
                DB::table('object_records')->where('id', $record->id)->update([
                    'stock_dimension_key' => $key,
                ]);
            }
        }

        Schema::table('object_records', function (Blueprint $table) {
            $table->unique(
                ['business_object_id', 'stock_dimension_key'],
                'object_records_stock_dimension_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('object_records', function (Blueprint $table) {
            $table->dropUnique('object_records_stock_dimension_unique');
            $table->dropColumn('stock_dimension_key');
        });
    }
};
