<?php

namespace Database\Factories;

use App\Models\SupportLibrary;
use App\Models\SupportLibraryVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportLibraryVersion> */
class SupportLibraryVersionFactory extends Factory
{
    public function definition(): array
    {
        $payload = json_decode(file_get_contents(resource_path('support-library/baseline.json')), true);

        return ['library_id' => SupportLibrary::factory(), 'revision' => 1, 'schema_version' => 'user-library-1',
            'rule_version' => SupportLibraryVersion::RULE_VERSION, 'payload_json' => $payload,
            'payload_sha256' => hash('sha256', json_encode($payload)), 'source_filename' => '支架库.xlsx',
            'source_sha256' => $payload['source']['sha256'], 'actor_id' => null, 'action' => 'initialize',
            'request_id' => 'system:'.fake()->uuid(), 'input_sha256' => hash('sha256', json_encode($payload)), 'created_at' => now()];
    }
}
