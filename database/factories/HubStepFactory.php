<?php

namespace Database\Factories;

use App\Models\HubRun;
use App\Models\HubStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HubStep> */
class HubStepFactory extends Factory
{
    public function definition(): array
    {
        return ['hub_run_id' => HubRun::factory(), 'stage' => 'analyze', 'round' => 0, 'status' => 'queued'];
    }
}
