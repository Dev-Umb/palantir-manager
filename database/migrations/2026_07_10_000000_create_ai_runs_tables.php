<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('conversation_id', 36)->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_request_id');
            $table->string('status', 25)->index();
            $table->text('input');
            $table->longText('answer')->nullable();
            $table->json('artifacts')->nullable();
            $table->json('sources')->nullable();
            $table->json('data_quality')->nullable();
            $table->json('usage')->nullable();
            $table->json('error')->nullable();
            $table->unsignedInteger('last_event_seq')->default(0);
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_request_id']);
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('ai_run_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('run_id')->constrained('ai_runs')->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('type', 60);
            $table->json('payload');
            $table->timestamp('created_at');

            $table->unique(['run_id', 'seq']);
            $table->index(['run_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_run_events');
        Schema::dropIfExists('ai_runs');
    }
};
