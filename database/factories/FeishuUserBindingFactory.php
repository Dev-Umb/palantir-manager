<?php

namespace Database\Factories;

use App\Models\FeishuUserBinding;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeishuUserBinding>
 */
class FeishuUserBindingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tenant_key' => 'tenant_'.fake()->unique()->lexify('????????'),
            'open_id' => 'ou_'.fake()->unique()->lexify('????????????????'),
            'verified_at' => now(),
        ];
    }
}
