<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('project_id');
            $table->string('type', 32);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('triggered_at');
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('object_records')->cascadeOnDelete();
            $table->unique(['project_id', 'type', 'user_id']);
            $table->index(['user_id', 'status', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_notifications');
    }
};
