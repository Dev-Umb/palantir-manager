<?php

namespace Database\Factories;

use App\Models\HubSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HubSubscription> */
class HubSubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'name' => '钢模板订阅', 'filters' => ['q' => '钢模板']];
    }
}
