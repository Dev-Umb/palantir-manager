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
        Schema::table('reimbursement_uploads', function (Blueprint $table) {
            $table->string('evidence_status')->nullable();
            $table->json('evidence_result')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reimbursement_uploads', function (Blueprint $table) {
            $table->dropColumn(['evidence_status', 'evidence_result']);
        });
    }
};
