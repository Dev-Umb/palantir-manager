<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class QuotationArchiveFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'adoption_key' => (string) Str::uuid(), 'title' => fake()->sentence(), 'snapshot' => ['reference_only' => true], 'snapshot_hash' => hash('sha256', '{}')];
    }
}
