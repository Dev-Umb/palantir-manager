<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wechat_account_bindings', function (Blueprint $table): void {
            $table->text('code_encrypted')->nullable();
        });
        DB::table('wechat_account_bindings')->update(['code_hash' => null, 'code_expires_at' => null]);
    }

    public function down(): void
    {
        Schema::table('wechat_account_bindings', function (Blueprint $table): void {
            $table->dropColumn('code_encrypted');
        });
    }
};
