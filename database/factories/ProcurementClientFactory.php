<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProcurementClientFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'name' => fake()->word(), 'token_hash' => hash('sha256', fake()->uuid()), 'expires_at' => now()->addDay()];
    }
}
