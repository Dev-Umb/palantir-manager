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
        Schema::create('finance_shipment_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_id')->unique()->constrained('object_records')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->json('orders');
            $table->timestamps();
        });
        Schema::create('finance_shipment_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('object_records')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('hash', 64);
            $table->string('name');
            $table->string('path');
            $table->json('preview');
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_shipment_imports');
        Schema::dropIfExists('finance_shipment_ledgers');
    }
};
