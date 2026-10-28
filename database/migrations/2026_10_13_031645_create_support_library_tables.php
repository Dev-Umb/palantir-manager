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
        Schema::create('support_libraries', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedBigInteger('current_revision')->default(0);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
        });
        Schema::create('support_library_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('library_id')->constrained('support_libraries')->restrictOnDelete();
            $table->unsignedBigInteger('revision');
            $table->string('schema_version');
            $table->string('rule_version');
            $table->json('payload_json');
            $table->string('payload_sha256', 64);
            $table->string('source_filename');
            $table->string('source_sha256', 64)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('request_id', 150);
            $table->string('input_sha256', 64);
            $table->timestamp('created_at');
            $table->unique(['library_id', 'revision']);
            $table->unique(['library_id', 'request_id']);
        });
        Schema::table('support_libraries', function (Blueprint $table): void {
            $table->foreign('current_version_id')->references('id')->on('support_library_versions')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_libraries', function (Blueprint $table): void {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('support_library_versions');
        Schema::dropIfExists('support_libraries');
    }
};
