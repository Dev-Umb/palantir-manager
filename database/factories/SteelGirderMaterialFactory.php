<?php

namespace Database\Factories;

use App\Models\SteelGirderMaterial;
use App\Models\SteelGirderProject;
use App\Models\StoredAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SteelGirderMaterial>
 */
class SteelGirderMaterialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => SteelGirderProject::factory(), 'source_attachment_id' => StoredAttachment::factory(), 'parsed_payload' => [], 'reviews' => [], 'display_unit' => 't',
        ];
    }
}
