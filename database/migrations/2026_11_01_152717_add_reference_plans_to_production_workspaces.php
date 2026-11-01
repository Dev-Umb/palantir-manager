<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_workspaces', function (Blueprint $table): void {
            $table->json('reference_plans')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('production_workspaces', function (Blueprint $table): void {
            $table->dropColumn('reference_plans');
        });
    }
};
