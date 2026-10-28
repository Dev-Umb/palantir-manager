<?php

namespace Database\Factories;

use App\Models\SupportLibrary;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportLibrary> */
class SupportLibraryFactory extends Factory
{
    public function definition(): array
    {
        return ['key' => 'default', 'current_revision' => 0, 'current_version_id' => null];
    }
}
