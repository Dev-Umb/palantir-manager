<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->string('request_hash', 64)->nullable()->after('client_request_id')->index();
            $table->foreignUuid('retry_parent_id')->nullable()->after('request_hash')
                ->constrained('ai_runs')->nullOnDelete();
            $table->unsignedSmallInteger('attempt_number')->default(1)->after('retry_parent_id');
            $table->json('context_snapshot')->nullable()->after('input');
            $table->json('provenance')->nullable()->after('sources');
            $table->string('failure_category', 40)->nullable()->after('error')->index();
            $table->string('cancel_reason', 80)->nullable()->after('cancel_requested_at');
        });

        DB::table('ai_runs')->whereNull('request_hash')->orderBy('created_at')->eachById(function (object $run): void {
            DB::table('ai_runs')->where('id', $run->id)->update([
                'request_hash' => hash('sha256', json_encode([
                    'conversation_id' => $run->conversation_id,
                    'message' => $run->input,
                    'retry_parent_id' => null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ]);
        }, column: 'id');
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropForeign(['retry_parent_id']);
            $table->dropIndex(['request_hash']);
            $table->dropIndex(['failure_category']);
            $table->dropColumn([
                'request_hash', 'retry_parent_id', 'attempt_number', 'context_snapshot',
                'provenance', 'failure_category', 'cancel_reason',
            ]);
        });
    }
};
