<?php

namespace Database\Factories;

use App\Models\ProcurementNotice;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProcurementObservationFactory extends Factory
{
    public function definition(): array
    {
        return ['notice_id' => ProcurementNotice::factory(), 'batch_id' => fn (array $a) => ProcurementNotice::findOrFail($a['notice_id'])->batch_id, 'item_id' => fake()->uuid(), 'status' => 'created', 'payload' => fn (array $a) => ProcurementNotice::findOrFail($a['notice_id'])->payload, 'warnings' => []];
    }
}
