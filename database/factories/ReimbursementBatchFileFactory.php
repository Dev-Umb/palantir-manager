<?php

namespace Database\Factories;

use App\Models\ReimbursementBatch;
use App\Models\ReimbursementBatchFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ReimbursementBatchFile> */
class ReimbursementBatchFileFactory extends Factory
{
    public function definition(): array
    {
        return ['batch_id' => ReimbursementBatch::factory(), 'name' => '发票.pdf', 'disk' => 'local', 'object_key' => 'invoice-batches/'.Str::uuid(), 'size' => 100, 'mime' => 'application/pdf'];
    }
}
