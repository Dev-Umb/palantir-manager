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
        Schema::create('stored_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('logical_path', 255)->unique();
            $table->string('disk', 80);
            $table->string('object_key', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('status', 24)->index();
            $table->timestamps();

            $table->unique(['disk', 'object_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stored_attachments');
    }
};
