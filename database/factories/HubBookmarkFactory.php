<?php

namespace Database\Factories;

use App\Models\HubBookmark;
use App\Models\HubNotice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HubBookmark> */
class HubBookmarkFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'hub_notice_id' => HubNotice::factory()];
    }
}
