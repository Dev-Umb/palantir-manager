<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_workspaces', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('revision')->default(0);
            $table->json('dataset');
            $table->json('before_restore')->nullable();
            $table->unsignedBigInteger('restore_revision')->nullable();
            $table->json('upgrade_snapshot')->nullable();
        });
        DB::table('production_workspaces')->insert(['id' => 1, 'revision' => 0, 'dataset' => '{"materialDemandPlans":[]}']);
        Schema::create('production_workspace_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('input_hash', 64);
            $table->unique(['user_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_workspace_requests');
        Schema::dropIfExists('production_workspaces');
    }
};
