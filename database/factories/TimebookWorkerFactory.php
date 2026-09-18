<?php

namespace Database\Factories;

use App\Models\TimebookWorker;
use App\Support\TimebookName;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimebookWorker>
 */
class TimebookWorkerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->name();

        return ['name' => $name, 'name_key' => TimebookName::key($name)];
    }
}
