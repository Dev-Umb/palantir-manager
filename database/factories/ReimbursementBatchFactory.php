<?php

namespace Database\Factories;

use App\Models\ReimbursementBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReimbursementBatch> */
class ReimbursementBatchFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'status' => 'uploading'];
    }
}
