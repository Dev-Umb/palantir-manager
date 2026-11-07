<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('finance_shipment_imports')->whereNull('ledger_id')->get() as $import) {
                $id = DB::table('finance_shipment_ledgers')->where('project_id', $import->project_id)->value('id');
                if (! $id) {
                    $id = DB::table('finance_shipment_ledgers')->insertGetId([
                        'project_id' => $import->project_id, 'revision' => 0, 'orders' => '[]',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                DB::table('finance_shipment_imports')->where('id', $import->id)->update(['ledger_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        // Keep stable import ownership when rolling application code back.
    }
};
