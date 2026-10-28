<?php

namespace Database\Factories;

use App\Models\Reimbursement;
use App\Models\ReimbursementVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReimbursementVersion> */
class ReimbursementVersionFactory extends Factory
{
    public function definition(): array
    {
        return ['reimbursement_id' => Reimbursement::factory(), 'user_id' => fn (array $a) => Reimbursement::findOrFail($a['reimbursement_id'])->user_id,
            'number' => 1, 'submission_key' => fake()->uuid(), 'submission_fingerprint' => hash('sha256', 'fixture'), 'title' => '差旅报销', 'type' => '差旅交通', 'note' => '客户拜访',
            'items' => [], 'total_cents' => 100, 'flow' => ['nodes' => [], 'cc' => [], 'triggers' => []], 'cc' => [], 'decisions' => [], 'node_index' => 0, 'status' => 'approved'];
    }
}
