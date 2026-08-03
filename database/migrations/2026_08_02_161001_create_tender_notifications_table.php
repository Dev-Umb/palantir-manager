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
        Schema::create('tender_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('tender_id');
            $table->string('type', 24);
            $table->string('deadline_type', 24);
            $table->string('stage', 24);
            $table->uuid('project_id')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('triggered_at');
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamps();

            $table->foreign('tender_id')->references('id')->on('object_records')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('object_records')->nullOnDelete();
            $table->unique(['tender_id', 'deadline_type', 'stage', 'user_id']);
            $table->index(['user_id', 'status', 'read_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tender_notifications');
    }
};
