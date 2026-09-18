<?php

namespace Database\Factories;

use App\Models\HubImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HubImport> */
class HubImportFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'rows' => [], 'errors' => []];
    }
}
