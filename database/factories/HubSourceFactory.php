<?php

namespace Database\Factories;

use App\Models\HubSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HubSource> */
class HubSourceFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => '公开招采来源', 'url' => 'https://example.com/', 'allowed_hosts' => ['example.com'], 'enabled' => false, 'adapter' => 'html', 'keywords' => ['钢模板'], 'status' => 'candidate', 'interval_minutes' => 360];
    }
}
