<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('object_records', function (Blueprint $table) {
            $table->string('workflow_key')->nullable()->unique()->after('stock_dimension_key');
            $table->json('workflow_target_roles')->nullable()->after('workflow_key');
            $table->timestamp('workflow_seen_at')->nullable()->after('workflow_target_roles');
            $table->foreignId('workflow_seen_by')->nullable()->after('workflow_seen_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('object_records', function (Blueprint $table) {
            $table->dropForeign(['workflow_seen_by']);
            $table->dropUnique(['workflow_key']);
            $table->dropColumn([
                'workflow_key',
                'workflow_target_roles',
                'workflow_seen_at',
                'workflow_seen_by',
            ]);
        });
    }
};
