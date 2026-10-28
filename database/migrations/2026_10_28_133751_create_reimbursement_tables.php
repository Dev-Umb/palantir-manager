<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reimbursements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('latest_version')->default(0);
            $table->timestamps();
        });
        Schema::create('reimbursement_workflows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('revision')->default(0);
            $table->json('definition');
            $table->timestamps();
        });
        DB::table('reimbursement_workflows')->insert(['id' => 1, 'revision' => 0, 'definition' => json_encode(['nodes' => [['name' => '管理员审批', 'mode' => 'any', 'people' => []]], 'cc' => [], 'triggers' => ['submitted', 'approved', 'rejected']]), 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('reimbursement_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reimbursement_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->uuid('submission_key');
            $table->string('submission_fingerprint', 64);
            $table->string('title', 120);
            $table->string('type', 32);
            $table->text('note');
            $table->json('items');
            $table->unsignedBigInteger('total_cents');
            $table->json('flow');
            $table->json('cc');
            $table->json('decisions');
            $table->unsignedInteger('node_index')->default(0);
            $table->string('status', 16)->default('pending')->index();
            $table->boolean('wechat_subscribed')->default(false);
            $table->string('wechat_binding_version')->nullable();
            $table->timestamps();
            $table->unique(['reimbursement_id', 'number']);
            $table->unique(['user_id', 'submission_key']);
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('reimbursement_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('reimbursement_versions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->unique(['version_id', 'user_id', 'kind']);
            $table->index(['user_id', 'kind']);
        });
        Schema::create('reimbursement_uploads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('stored_attachment_id');
            $table->foreign('stored_attachment_id')->references('id')->on('stored_attachments')->restrictOnDelete();
            $table->foreignId('reimbursement_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('reimbursement_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('reimbursement_versions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('event', 40);
            $table->string('message');
            $table->timestamp('read_at')->nullable();
            $table->string('delivery_status', 24)->default('not_required');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('delivery_error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
            $table->unique(['version_id', 'user_id', 'event']);
            $table->index(['user_id', 'read_at', 'id']);
            $table->index(['delivery_status', 'attempts']);
        });
    }

    public function down(): void
    {
        foreach (['reimbursement_notices', 'reimbursement_uploads', 'reimbursement_access', 'reimbursement_versions', 'reimbursement_workflows', 'reimbursements'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
