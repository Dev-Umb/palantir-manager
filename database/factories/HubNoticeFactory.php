<?php

namespace Database\Factories;

use App\Models\HubNotice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HubNotice> */
class HubNoticeFactory extends Factory
{
    public function definition(): array
    {
        return ['identity_key' => fake()->sha256(), 'project_key' => fake()->sha256(), 'title' => '钢模板采购项目', 'kind' => 'notice', 'buyer' => '某工程有限公司', 'product' => '钢模板', 'revision' => 1, 'facts' => [], 'missing' => [], 'published_at' => now()];
    }
}
