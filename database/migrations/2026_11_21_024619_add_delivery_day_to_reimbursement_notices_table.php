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
        Schema::table('reimbursement_notices', function (Blueprint $table): void {
            $table->date('delivery_day')->nullable();
            $table->unique(['user_id', 'delivery_day']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reimbursement_notices', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'delivery_day']);
            $table->dropColumn('delivery_day');
        });
    }
};
