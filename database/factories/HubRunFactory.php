<?php

namespace Database\Factories;

use App\Models\HubRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HubRun> */
class HubRunFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'kind' => 'research', 'query' => '钢模板', 'status' => 'queued', 'stage' => 'plan', 'round' => 0];
    }
}
