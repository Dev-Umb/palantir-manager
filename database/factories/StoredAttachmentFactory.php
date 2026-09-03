<?php

namespace Database\Factories;

use App\Models\StoredAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoredAttachment>
 */
class StoredAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->uuid().'.pdf';

        return [
            'logical_path' => 'attachments/'.$name,
            'disk' => 'local',
            'object_key' => 'attachments/'.$name,
            'original_name' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'sha256' => hash('sha256', fake()->uuid()),
            'status' => StoredAttachment::STATUS_STAGED,
        ];
    }
}
