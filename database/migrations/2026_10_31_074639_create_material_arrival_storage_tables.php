<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_arrival_workspaces', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('revision')->default(0);
            $table->json('dataset');
            $table->json('before_restore')->nullable();
        });
        DB::table('material_arrival_workspaces')->insert(['id' => 1, 'revision' => 0, 'dataset' => json_encode(['records' => [], 'imports' => [], 'revision' => 0, 'updatedAt' => null])]);
        Schema::create('material_arrival_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 20);
            $table->unsignedBigInteger('revision')->default(0);
            $table->text('value')->nullable();
            $table->unique(['user_id', 'key']);
        });
        Schema::create('material_arrival_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('input_hash', 64);
            $table->json('result');
            $table->timestamp('created_at');
            $table->unique(['user_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_arrival_requests');
        Schema::dropIfExists('material_arrival_preferences');
        Schema::dropIfExists('material_arrival_workspaces');
    }
};
