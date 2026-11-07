<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_shipment_ledgers', function (Blueprint $table) {
            $table->dropUnique(['project_id']);
            $table->uuid('project_id')->nullable()->change();
            $table->index('project_id');
            $table->string('title')->nullable();
            $table->string('source_key', 64)->nullable()->unique();
        });
        Schema::table('finance_shipment_imports', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'hash']);
            $table->uuid('project_id')->nullable()->change();
            $table->foreignId('ledger_id')->nullable()->constrained('finance_shipment_ledgers')->restrictOnDelete();
            $table->unique(['ledger_id', 'hash']);
        });
    }

    public function down(): void
    {
        if (DB::table('finance_shipment_ledgers')->whereNull('project_id')->exists()
            || DB::table('finance_shipment_ledgers')->select('project_id')->groupBy('project_id')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('存在独立台账，回滚将丢失关联语义；请保留财务表并使用前向修复。');
        }
        Schema::table('finance_shipment_imports', function (Blueprint $table) {
            $table->dropUnique(['ledger_id', 'hash']);
            $table->dropConstrainedForeignId('ledger_id');
            $table->uuid('project_id')->nullable(false)->change();
            $table->unique(['project_id', 'hash']);
        });
        Schema::table('finance_shipment_ledgers', function (Blueprint $table) {
            $table->dropIndex(['project_id']);
            $table->uuid('project_id')->nullable(false)->change();
            $table->unique('project_id');
            $table->dropUnique(['source_key']);
            $table->dropColumn(['title', 'source_key']);
        });
    }
};
