<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reimbursement_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->json('payload');
            $table->unsignedInteger('revision')->default(0);
            $table->foreignId('version_id')->nullable()->constrained('reimbursement_versions');
            $table->timestamps();
            $table->index(['user_id', 'version_id', 'updated_at']);
        });
        Schema::table('reimbursement_uploads', function (Blueprint $table): void {
            $table->foreignUuid('draft_id')->nullable()->constrained('reimbursement_drafts');
            $table->uuid('draft_operation')->nullable()->unique();
            $table->unsignedInteger('draft_revision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reimbursement_uploads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('draft_id');
            $table->dropUnique(['draft_operation']);
            $table->dropColumn(['draft_operation', 'draft_revision']);
        });
        Schema::dropIfExists('reimbursement_drafts');
    }
};
