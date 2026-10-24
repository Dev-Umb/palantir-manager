<?php

namespace Database\Factories;

use App\Models\ProcurementClient;
use App\Support\ProcurementPayload;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProcurementBatchFactory extends Factory
{
    public function definition(): array
    {
        $payload = json_decode(file_get_contents(resource_path('skills/procurement-publish/references/example.json')), true);

        return ['id' => (string) Str::uuid(), 'client_id' => ProcurementClient::factory(), 'payload' => $payload, 'payload_hash' => app(ProcurementPayload::class)->hash($payload), 'status' => 'validated', 'expires_at' => now()->addDay()];
    }
}
