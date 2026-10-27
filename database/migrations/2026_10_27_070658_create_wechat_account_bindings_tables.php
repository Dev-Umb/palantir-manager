<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wechat_account_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('appid', 64);
            $table->string('openid', 128)->nullable();
            $table->uuid('version');
            $table->string('code_hash', 64)->nullable()->unique();
            $table->timestamp('code_expires_at')->nullable();
            $table->timestamp('bound_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'appid']);
            $table->unique(['appid', 'openid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wechat_account_bindings');
    }
};
