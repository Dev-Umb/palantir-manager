<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reimbursement_batches', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained();
            $t->string('status')->default('uploading');
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });
        Schema::create('reimbursement_batch_files', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('batch_id')->constrained('reimbursement_batches');
            $t->string('name');
            $t->string('disk');
            $t->string('object_key')->unique();
            $t->unsignedBigInteger('size');
            $t->string('mime');
            $t->string('status')->default('uploading');
            $t->string('sha256', 64)->nullable()->index();
            $t->foreignUuid('upload_id')->nullable()->constrained('reimbursement_uploads');
            $t->foreignUuid('draft_id')->nullable()->constrained('reimbursement_drafts');
            $t->json('result')->nullable();
            $t->json('issues')->nullable();
            $t->boolean('reviewed')->default(false);
            $t->text('error')->nullable();
            $t->timestamps();
            $t->index(['batch_id', 'status']);
        });
        Schema::table('reimbursement_drafts', function (Blueprint $t): void {
            $t->foreignUuid('batch_id')->nullable()->constrained('reimbursement_batches');
        });
    }

    public function down(): void
    {
        Schema::table('reimbursement_drafts', fn (Blueprint $t) => $t->dropConstrainedForeignId('batch_id'));
        Schema::dropIfExists('reimbursement_batch_files');
        Schema::dropIfExists('reimbursement_batches');
    }
};
