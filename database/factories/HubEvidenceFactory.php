<?php

namespace Database\Factories;

use App\Models\HubEvidence;
use App\Models\HubNotice;
use App\Models\HubSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HubEvidence> */
class HubEvidenceFactory extends Factory
{
    public function definition(): array
    {
        return ['hub_notice_id' => HubNotice::factory(), 'hub_source_id' => HubSource::factory(), 'url' => 'https://example.com/notice', 'url_hash' => fake()->sha256(), 'content_hash' => fake()->sha256(), 'text' => '钢模板采购公开公告', 'extraction' => [], 'fetched_at' => now()];
    }
}
