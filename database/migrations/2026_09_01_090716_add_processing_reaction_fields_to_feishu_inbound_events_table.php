<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('feishu_inbound_events', 'processing_reaction_id')) {
            return;
        }

        Schema::table('feishu_inbound_events', function (Blueprint $table) {
            $table->string('processing_reaction_id', 120)->nullable()->after('reply_message_id');
            $table->timestamp('processing_reaction_removed_at')->nullable()->after('processing_reaction_id');
            $table->string('processing_reaction_error', 80)->nullable()->after('processing_reaction_removed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        /**
         * This compatibility migration is intentionally not reversible because
         * fresh installations receive these columns from the original table migration.
         */
    }
};
