<?php

namespace Database\Factories;

use App\Models\ReimbursementNotice;
use App\Models\ReimbursementVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReimbursementNotice> */
class ReimbursementNoticeFactory extends Factory
{
    public function definition(): array
    {
        return ['version_id' => ReimbursementVersion::factory(), 'user_id' => fn (array $a) => ReimbursementVersion::findOrFail($a['version_id'])->user_id, 'event' => 'approved', 'message' => '报销审批通过'];
    }
}
