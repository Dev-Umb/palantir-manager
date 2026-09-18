<?php

namespace Database\Factories;

use App\Models\TimebookEntry;
use App\Models\TimebookWorker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimebookEntry>
 */
class TimebookEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'worker_id' => TimebookWorker::factory(),
            'day' => '2026-09-18',
            'half_days' => 2,
            'overtime_minutes' => 0,
            'project' => '',
            'note' => '',
        ];
    }
}
