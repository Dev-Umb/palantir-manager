<?php

namespace Database\Factories;

use App\Models\ReimbursementUpload;
use App\Models\StoredAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReimbursementUpload> */
class ReimbursementUploadFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'stored_attachment_id' => StoredAttachment::factory()];
    }
}
