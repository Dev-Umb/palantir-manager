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
        Schema::create('project_reminder_states', function (Blueprint $table) {
            $table->id();
            $table->uuid('project_id');
            $table->string('type', 32);
            $table->timestamp('anchor_at');
            $table->timestamp('next_due_at')->index();
            $table->timestamp('last_triggered_at')->nullable();
            $table->unsignedInteger('occurrences')->default(0);
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('object_records')->cascadeOnDelete();
            $table->unique(['project_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_reminder_states');
    }
};
