<?php

namespace Database\Factories;

use App\Models\HubCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HubCompany> */
class HubCompanyFactory extends Factory
{
    public function definition(): array
    {
        return ['kind' => 'capability', 'name' => '我方钢模板能力', 'data' => ['product' => '钢模板'], 'status' => 'draft', 'revision' => 1];
    }
}
