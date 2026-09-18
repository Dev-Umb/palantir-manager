<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('url', 2048);
            $table->json('allowed_hosts');
            $table->boolean('enabled')->default(false);
            $table->string('status')->default('candidate');
            $table->string('adapter')->default('html');
            $table->unsignedInteger('interval_minutes')->default(720);
            $table->json('keywords')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        Schema::create('hub_notices', function (Blueprint $table): void {
            $table->id();
            $table->string('identity_key', 64)->unique();
            $table->string('project_key', 64)->index();
            $table->string('title', 500);
            $table->string('buyer')->nullable()->index();
            $table->string('group_name')->nullable()->index();
            $table->boolean('group_confirmed')->default(false);
            $table->string('project_code')->nullable();
            $table->string('lot')->nullable();
            $table->string('round')->nullable();
            $table->string('kind')->index();
            $table->string('region')->nullable()->index();
            $table->string('product')->nullable()->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('deadline')->nullable()->index();
            $table->json('facts');
            $table->json('missing')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('hub_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hub_notice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hub_source_id')->constrained()->restrictOnDelete();
            $table->string('url', 2048);
            $table->string('url_hash', 64)->index();
            $table->string('content_hash', 64);
            $table->longText('text');
            $table->string('raw_path')->nullable();
            $table->json('extraction');
            $table->json('attachments')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();
            $table->unique(['hub_notice_id', 'url_hash', 'content_hash'], 'hub_evidence_unique');
        });
        Schema::create('hub_companies', function (Blueprint $table): void {
            $table->id();
            $table->string('kind')->index();
            $table->string('name');
            $table->json('data');
            $table->string('status')->default('draft')->index();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('hub_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('rows');
            $table->json('errors');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('hub_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hub_notice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hub_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('query', 500)->default('');
            $table->string('status')->default('queued')->index();
            $table->string('stage')->default('plan');
            $table->unsignedTinyInteger('round')->default(0);
            $table->json('snapshot')->nullable();
            $table->json('result')->nullable();
            $table->json('audit')->nullable();
            $table->json('score')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('stale_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
        Schema::create('hub_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hub_run_id')->constrained()->cascadeOnDelete();
            $table->string('stage');
            $table->unsignedTinyInteger('round');
            $table->string('status');
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('usage')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['hub_run_id', 'stage', 'round']);
        });
        Schema::create('hub_bookmarks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hub_notice_id')->constrained()->cascadeOnDelete();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'hub_notice_id']);
        });
        Schema::create('hub_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('filters');
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['hub_subscriptions', 'hub_bookmarks', 'hub_steps', 'hub_runs', 'hub_imports', 'hub_companies', 'hub_evidence', 'hub_notices', 'hub_sources'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
