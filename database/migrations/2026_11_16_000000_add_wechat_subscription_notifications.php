<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wechat_account_bindings', function (Blueprint $table): void {
            $table->text('openid_encrypted')->nullable();
        });

        Schema::create('wechat_subscription_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('template_key', 40);
            $table->string('template_id', 80);
            $table->unsignedInteger('available_count')->default(0);
            $table->timestamp('last_accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'template_id']);
            $table->index(['user_id', 'template_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wechat_subscription_grants');
        Schema::table('wechat_account_bindings', function (Blueprint $table): void {
            $table->dropColumn('openid_encrypted');
        });
    }
};
