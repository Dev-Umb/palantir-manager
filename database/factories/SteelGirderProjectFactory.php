<?php

namespace Database\Factories;

use App\Models\SteelGirderProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SteelGirderProject>
 */
class SteelGirderProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'revision' => 1,
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }
}
