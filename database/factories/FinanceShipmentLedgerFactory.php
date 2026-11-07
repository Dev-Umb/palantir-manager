<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class FinanceShipmentLedgerFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => fake()->sentence(3), 'project_id' => null, 'orders' => [], 'revision' => 0];
    }
}
