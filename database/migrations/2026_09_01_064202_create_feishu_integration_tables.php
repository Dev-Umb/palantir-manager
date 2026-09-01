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
        Schema::create('feishu_user_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tenant_key', 80);
            $table->string('open_id', 80);
            $table->string('conversation_id', 36)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_key', 'open_id']);
            $table->unique(['user_id', 'tenant_key']);
        });

        Schema::create('feishu_inbound_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 120)->unique();
            $table->string('event_type', 120);
            $table->string('tenant_key', 80)->nullable();
            $table->string('sender_open_id', 80)->nullable();
            $table->string('message_id', 120)->nullable();
            $table->foreignId('binding_id')->nullable()->constrained('feishu_user_bindings')->nullOnDelete();
            $table->foreignUuid('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->string('status', 24)->index();
            $table->json('payload');
            $table->string('reply_message_id', 120)->nullable();
            $table->string('processing_reaction_id', 120)->nullable();
            $table->timestamp('processing_reaction_removed_at')->nullable();
            $table->string('processing_reaction_error', 80)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 40);
            $table->string('source_id', 80);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 24)->default('feishu');
            $table->unsignedInteger('occurrence');
            $table->string('idempotency_key', 191)->unique();
            $table->string('status', 24)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('external_message_id', 120)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('feishu_inbound_events');
        Schema::dropIfExists('feishu_user_bindings');
    }
};
