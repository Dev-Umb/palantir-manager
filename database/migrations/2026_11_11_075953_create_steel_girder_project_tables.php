<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('steel_girder_projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['deleted_at', 'updated_at']);
        });
        Schema::create('steel_girder_drawings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('steel_girder_projects')->restrictOnDelete();
            $table->foreignUuid('stored_attachment_id')->constrained('stored_attachments')->restrictOnDelete();
            $table->string('sha256', 64);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['project_id', 'sha256']);
        });
        Schema::create('steel_girder_materials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('steel_girder_projects')->restrictOnDelete();
            $table->foreignUuid('source_attachment_id')->constrained('stored_attachments')->restrictOnDelete();
            $table->jsonb('parsed_payload');
            $table->jsonb('reviews');
            $table->string('display_unit', 2)->default('t');
            $table->timestamps();
        });
        Schema::table('steel_girder_projects', function (Blueprint $table): void {
            $table->foreignUuid('current_material_id')->nullable()->constrained('steel_girder_materials')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('steel_girder_projects', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_material_id'));
        Schema::dropIfExists('steel_girder_materials');
        Schema::dropIfExists('steel_girder_drawings');
        Schema::dropIfExists('steel_girder_projects');
    }
};
