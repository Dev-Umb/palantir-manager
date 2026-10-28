<?php

namespace Database\Factories;

use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Reimbursement> */
class ReimbursementFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'latest_version' => 1];
    }
}
