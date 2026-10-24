<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_clients', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('procurement_batches', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('client_id')->constrained('procurement_clients');
            $t->string('payload_hash', 64);
            $t->string('idempotency_key', 120)->nullable();
            $t->json('payload');
            $t->json('results')->nullable();
            $t->string('status')->default('validated');
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['client_id', 'idempotency_key']);
        });
        Schema::create('procurement_notices', function (Blueprint $t): void {
            $t->id();
            $t->string('identity_key', 64)->unique();
            $t->foreignId('client_id')->constrained('procurement_clients');
            $t->foreignId('user_id')->constrained();
            $t->foreignUuid('batch_id')->constrained('procurement_batches');
            $t->string('title', 500);
            $t->string('method', 100)->index();
            $t->string('priority', 30)->index();
            $t->string('status', 30);
            $t->boolean('invited')->default(false)->index();
            $t->boolean('has_issues')->default(false);
            $t->date('published_date')->nullable()->index();
            $t->dateTime('registration_at')->nullable()->index();
            $t->dateTime('deadline_at')->nullable()->index();
            $t->json('dates');
            $t->json('payload');
            $t->string('content_hash', 64);
            $t->timestamps();
        });
        Schema::create('procurement_observations', function (Blueprint $t): void {
            $t->id();
            $t->foreignUuid('batch_id')->constrained('procurement_batches');
            $t->foreignId('notice_id')->nullable()->constrained('procurement_notices');
            $t->string('item_id', 255);
            $t->string('status');
            $t->json('payload');
            $t->json('warnings');
            $t->timestamps();
            $t->unique(['batch_id', 'item_id']);
        });
    }

    public function down(): void
    {
        foreach (['procurement_observations', 'procurement_notices', 'procurement_batches', 'procurement_clients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
