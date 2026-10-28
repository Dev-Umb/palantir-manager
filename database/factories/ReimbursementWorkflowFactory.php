<?php

namespace Database\Factories;

use App\Models\ReimbursementWorkflow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReimbursementWorkflow> */
class ReimbursementWorkflowFactory extends Factory
{
    public function definition(): array
    {
        return ['revision' => 1, 'definition' => ['nodes' => [['name' => '经理审批', 'mode' => 'any', 'people' => [User::factory()->create()->id]]], 'cc' => [], 'triggers' => []]];
    }
}
