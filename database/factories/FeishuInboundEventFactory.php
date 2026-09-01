<?php

namespace Database\Factories;

use App\Models\FeishuInboundEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeishuInboundEvent>
 */
class FeishuInboundEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => fake()->uuid(),
            'event_type' => 'im.message.receive_v1',
            'status' => 'received',
            'payload' => [],
        ];
    }
}
