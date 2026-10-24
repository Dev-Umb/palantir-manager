<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (['hub_subscriptions', 'hub_bookmarks', 'hub_steps', 'hub_runs', 'hub_imports', 'hub_companies', 'hub_evidence', 'hub_notices', 'hub_sources'] as $table) {
                DB::table($table)->delete();
            }
        });
    }

    public function down(): void
    {
        // Legacy data retirement is intentionally irreversible; rollback cannot recreate records.
    }
};
