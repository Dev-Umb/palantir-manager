<?php

namespace Database\Factories;

use App\Models\SteelGirderDrawing;
use App\Models\SteelGirderProject;
use App\Models\StoredAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SteelGirderDrawing>
 */
class SteelGirderDrawingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => SteelGirderProject::factory(), 'stored_attachment_id' => StoredAttachment::factory(), 'sha256' => hash('sha256', fake()->uuid()),
        ];
    }
}
