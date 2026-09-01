<?php

namespace Database\Factories;

use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationDelivery>
 */
class NotificationDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_type' => 'project_notification',
            'source_id' => (string) fake()->randomNumber(),
            'user_id' => User::factory(),
            'channel' => 'feishu',
            'occurrence' => 1,
            'idempotency_key' => fake()->uuid(),
            'status' => NotificationDelivery::STATUS_PENDING,
        ];
    }
}
