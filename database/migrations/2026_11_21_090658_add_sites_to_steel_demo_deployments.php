<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('steel_demo_sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->uuid('current_id')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->unsignedInteger('published_sequence')->default(0);
            $table->timestamps();
        });
        Schema::table('steel_demo_deployments', function (Blueprint $table) {
            $table->foreignUuid('site_id')->nullable()->constrained('steel_demo_sites');
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('ready');
            $table->string('operator')->nullable();
            $table->text('failure')->nullable();
            $table->unique(['site_id', 'version']);
        });
        Schema::create('steel_demo_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('deployment_id')->constrained('steel_demo_deployments');
            $table->string('action');
            $table->string('operator')->nullable();
            $table->timestamp('created_at');
        });
        DB::table('steel_demo_deployments')->orderBy('id')->each(function ($row) {
            DB::table('steel_demo_sites')->insert([
                'id' => $row->id, 'name' => pathinfo($row->name, PATHINFO_FILENAME), 'current_id' => $row->withdrawing ? null : $row->id,
                'sequence' => 1, 'published_sequence' => 1, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            ]);
            DB::table('steel_demo_deployments')->where('id', $row->id)->update(['site_id' => $row->id, 'status' => $row->withdrawing ? 'withdrawing' : 'ready']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('steel_demo_events');
        Schema::table('steel_demo_deployments', function (Blueprint $table) {
            $table->dropUnique(['site_id', 'version']);
            $table->dropConstrainedForeignId('site_id');
            $table->dropColumn(['version', 'status', 'operator', 'failure']);
        });
        Schema::dropIfExists('steel_demo_sites');
    }
};
