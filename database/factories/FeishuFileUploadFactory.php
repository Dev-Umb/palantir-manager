<?php

namespace Database\Factories;

use App\Models\FeishuFileUpload;
use App\Models\FeishuInboundEvent;
use App\Models\FeishuUserBinding;
use App\Models\StoredAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeishuFileUpload>
 */
class FeishuFileUploadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inbound_event_id' => FeishuInboundEvent::factory(),
            'binding_id' => FeishuUserBinding::factory(),
            'stored_attachment_id' => StoredAttachment::factory(),
            'conversation_key' => 'user:'.fake()->uuid(),
            'file_key' => 'file_'.fake()->uuid(),
            'status' => FeishuFileUpload::STATUS_PENDING,
        ];
    }
}
