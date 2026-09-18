<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('timebook_workers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('name_key', 160)->unique();
            $table->timestamps();
        });
        Schema::create('timebook_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('timebook_workers')->restrictOnDelete();
            $table->date('day')->index();
            $table->unsignedSmallInteger('half_days');
            $table->unsignedSmallInteger('overtime_minutes');
            $table->string('project', 80)->default('');
            $table->string('note', 500)->default('');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedSmallInteger('deleted')->default(0);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX timebook_one_person_day ON timebook_entries (worker_id, day) WHERE deleted = 0');
        Schema::create('timebook_entry_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('timebook_entries')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('action', 16);
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timebook_entry_audits');
        Schema::dropIfExists('timebook_entries');
        Schema::dropIfExists('timebook_workers');
    }
};
