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
        Schema::create('feishu_file_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_event_id')->unique()->constrained('feishu_inbound_events')->cascadeOnDelete();
            $table->foreignId('binding_id')->constrained('feishu_user_bindings')->cascadeOnDelete();
            $table->foreignUuid('stored_attachment_id')->constrained('stored_attachments')->restrictOnDelete();
            $table->string('conversation_key', 160)->index();
            $table->string('file_key', 255);
            $table->string('status', 24)->index();
            $table->foreignUuid('project_id')->nullable()->constrained('object_records')->nullOnDelete();
            $table->foreignUuid('contract_id')->nullable()->constrained('object_records')->nullOnDelete();
            $table->string('attachment_field', 80)->nullable();
            $table->timestamp('attached_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['binding_id', 'conversation_key', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feishu_file_uploads');
    }
};
